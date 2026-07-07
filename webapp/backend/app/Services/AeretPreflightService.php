<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AeretPreflightService
{
    private const BASE_URL = 'https://aeret.kaartviewer.nl/admin/v2/kaartviewerapi/bookmark/dpf_basic/bookmarkpresentation';
    private const AIRSPACE_LAYER_ID = 834;
    private const NATURA2000_LAYER_ID = 899;
    private const VITAL_INFRA_LAYER_ID = 1391;
    private const NOTAM_LAYER_ID = 1420;
    private const AIRSPACE_START_ID = 1;
    private const AIRSPACE_END_ID = 651;
    private const NATURA2000_START_ID = 1;
    private const NATURA2000_END_ID = 588;
    private const VITAL_INFRA_START_ID = 1;
    private const VITAL_INFRA_END_ID = 5;
    private const NOTAM_START_ID = 1;
    private const NOTAM_END_ID = 440;
    private const REQUEST_BATCH_SIZE = 50;
    private const REQUEST_BATCH_DELAY_MS = 100;
    private const CACHE_VERSION = 'v3';

    /**
     * @return array{type: string, features: list<array<string, mixed>>, meta?: array<string, mixed>}
     */
    public function nearby(float $latitude, float $longitude, int $radiusMeters = 5000): array
    {
        $radiusMeters = max(1000, min(25000, $radiusMeters));
        $features = [];

        foreach ($this->defaultLayers() as $layer) {
            $collection = $this->fetchAeretLayer($layer['layer_id'], $layer['start_id'], $layer['end_id']);
            foreach ($collection['features'] as $feature) {
                $classification = $this->classifyFeature($feature, $layer['layer_id']);
                if ($classification === null) {
                    continue;
                }

                if ($layer['layer_id'] === self::NOTAM_LAYER_ID && ! $this->isActiveNotam($feature)) {
                    continue;
                }

                if ($layer['layer_id'] === self::NOTAM_LAYER_ID && ! $this->isDroneRelevantNotam($feature)) {
                    continue;
                }

                $distanceMeters = $layer['layer_id'] === self::NOTAM_LAYER_ID
                    ? $this->distanceToNotamLocation($feature, $latitude, $longitude)
                    : $this->distanceToGeometry($feature['geometry'] ?? null, $latitude, $longitude);
                if ($distanceMeters === null || $distanceMeters > $radiusMeters) {
                    continue;
                }

                $feature['properties']['_aeret'] = array_merge($feature['properties']['_aeret'] ?? [], $classification, [
                    'distance_m' => round($distanceMeters),
                    'within_radius_m' => $radiusMeters,
                ]);
                $features[] = $feature;
            }
        }

        usort($features, function (array $left, array $right): int {
            $leftDistance = $left['properties']['_aeret']['distance_m'] ?? PHP_INT_MAX;
            $rightDistance = $right['properties']['_aeret']['distance_m'] ?? PHP_INT_MAX;

            return $leftDistance <=> $rightDistance;
        });

        return [
            'type' => 'FeatureCollection',
            'features' => array_values($features),
            'meta' => [
                'center' => [
                    'latitude' => round($latitude, 7),
                    'longitude' => round($longitude, 7),
                ],
                'radius_m' => $radiusMeters,
                'feature_count' => count($features),
                'counts' => $this->countsByCategory($features),
                'source' => 'Aeret KaartViewer Drone PreFlight Basic',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchAeretFeature(int $layerId, int $objectId): ?array
    {
        if (! $this->isAllowedLayer($layerId) || $objectId < 1) {
            return null;
        }

        $cacheKey = sprintf('aeret:feature:%d:%d:%s', $layerId, $objectId, self::CACHE_VERSION);
        $cached = Cache::get($cacheKey);
        if ($cached === false) {
            return null;
        }
        if (is_array($cached)) {
            return $cached;
        }

        $feature = $this->fetchAeretFeatureUncached($layerId, $objectId);
        Cache::put($cacheKey, $feature ?? false, $this->featureCacheTtl($layerId));

        return $feature;
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function fetchAeretLayer(int $layerId, int $startId, int $endId): array
    {
        if (! $this->isAllowedLayer($layerId)) {
            return $this->emptyCollection($layerId, $startId, $endId);
        }

        $startId = max(1, $startId);
        $endId = max($startId, $endId);
        $cacheKey = sprintf('aeret:layer:%d:%d:%d:%s', $layerId, $startId, $endId, self::CACHE_VERSION);

        return Cache::remember($cacheKey, $this->layerCacheTtl($layerId), function () use ($layerId, $startId, $endId): array {
            $features = [];

            foreach (array_chunk(range($startId, $endId), self::REQUEST_BATCH_SIZE) as $chunkIndex => $ids) {
                try {
                    /** @var array<string, Response> $responses */
                    $responses = Http::pool(function (Pool $pool) use ($ids, $layerId): array {
                        return array_map(
                            fn (int $objectId) => $pool
                                ->as((string) $objectId)
                                ->connectTimeout(3)
                                ->timeout(6)
                                ->acceptJson()
                                ->get($this->featureUrl($layerId, $objectId)),
                            $ids,
                        );
                    });
                } catch (Throwable $exception) {
                    Log::warning('Aeret layer batch fetch failed.', [
                        'layer_id' => $layerId,
                        'start_id' => $ids[0] ?? null,
                        'end_id' => $ids[array_key_last($ids)] ?? null,
                        'error' => $exception->getMessage(),
                    ]);
                    $responses = [];
                }

                foreach ($responses as $objectId => $response) {
                    $feature = $this->featureFromResponse($layerId, (int) $objectId, $response);
                    if ($feature !== null) {
                        $features[] = $feature;
                    }
                }

                if ($chunkIndex < (int) floor(($endId - $startId) / self::REQUEST_BATCH_SIZE)) {
                    usleep(self::REQUEST_BATCH_DELAY_MS * 1000);
                }
            }

            return [
                'type' => 'FeatureCollection',
                'features' => $features,
                'meta' => [
                    'layer_id' => $layerId,
                    'start_id' => $startId,
                    'end_id' => $endId,
                    'feature_count' => count($features),
                    'source' => 'Aeret KaartViewer Drone PreFlight Basic',
                ],
            ];
        });
    }

    /**
     * @return array<int, array{layer_id: int, start_id: int, end_id: int}>
     */
    private function defaultLayers(): array
    {
        return [
            ['layer_id' => self::AIRSPACE_LAYER_ID, 'start_id' => self::AIRSPACE_START_ID, 'end_id' => self::AIRSPACE_END_ID],
            ['layer_id' => self::NATURA2000_LAYER_ID, 'start_id' => self::NATURA2000_START_ID, 'end_id' => self::NATURA2000_END_ID],
            ['layer_id' => self::VITAL_INFRA_LAYER_ID, 'start_id' => self::VITAL_INFRA_START_ID, 'end_id' => self::VITAL_INFRA_END_ID],
            ['layer_id' => self::NOTAM_LAYER_ID, 'start_id' => self::NOTAM_START_ID, 'end_id' => self::NOTAM_END_ID],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAeretFeatureUncached(int $layerId, int $objectId): ?array
    {
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get($this->featureUrl($layerId, $objectId));

            return $this->featureFromResponse($layerId, $objectId, $response);
        } catch (Throwable $exception) {
            Log::warning('Aeret feature fetch failed.', [
                'layer_id' => $layerId,
                'object_id' => $objectId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function featureFromResponse(int $layerId, int $objectId, Response $response): ?array
    {
        if (in_array($response->status(), [403, 404], true)) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning('Aeret feature returned unsuccessful status.', [
                'layer_id' => $layerId,
                'object_id' => $objectId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        $tab = $payload['mainTabs'][0] ?? null;
        if (! is_array($tab)) {
            return null;
        }

        $item = $tab['features'][0] ?? null;
        $sourceFeature = is_array($item) && is_array($item['feature'] ?? null) ? $item['feature'] : null;
        if ($sourceFeature === null || ! is_array($sourceFeature['geometry'] ?? null)) {
            return null;
        }

        $geometry = $this->geoJsonGeometry($sourceFeature['geometry'], (string) ($sourceFeature['projection'] ?? 'EPSG:28992'));
        if ($geometry === null) {
            return null;
        }

        $properties = $this->flattenFeatureInfo($item['featureInfo'] ?? []);
        $layerName = is_string($tab['Name'] ?? null) ? $tab['Name'] : $this->layerName($layerId);

        $properties['_aeret'] = [
            'layer_id' => $layerId,
            'layer_name' => $layerName,
            'object_id' => $objectId,
            'source_feature_id' => is_string($sourceFeature['id'] ?? null) ? $sourceFeature['id'] : null,
            'source_url' => $this->featureUrl($layerId, $objectId),
        ];

        return [
            'type' => 'Feature',
            'id' => "aeret:{$layerId}:{$objectId}",
            'geometry' => $geometry,
            'properties' => $properties,
        ];
    }

    /**
     * @param array<string, mixed> $geometry
     * @return array<string, mixed>|null
     */
    private function geoJsonGeometry(array $geometry, string $projection): ?array
    {
        $type = is_string($geometry['type'] ?? null) ? $geometry['type'] : null;
        if ($type === null || ! array_key_exists('coordinates', $geometry)) {
            return null;
        }

        $coordinates = $geometry['coordinates'];
        if ($projection === 'EPSG:28992') {
            $coordinates = $this->rdCoordinatesToWgs84($coordinates);
        }

        return [
            'type' => $type,
            'coordinates' => $coordinates,
        ];
    }

    private function rdCoordinatesToWgs84(mixed $coordinates): mixed
    {
        if ($this->isCoordinatePair($coordinates)) {
            /** @var array{0: float|int|string, 1: float|int|string} $coordinates */
            [$longitude, $latitude] = $this->rdToWgs84((float) $coordinates[0], (float) $coordinates[1]);

            return [$longitude, $latitude];
        }

        if (! is_array($coordinates)) {
            return $coordinates;
        }

        return array_map(fn (mixed $item): mixed => $this->rdCoordinatesToWgs84($item), $coordinates);
    }

    private function isCoordinatePair(mixed $coordinates): bool
    {
        return is_array($coordinates)
            && count($coordinates) >= 2
            && is_numeric($coordinates[0] ?? null)
            && is_numeric($coordinates[1] ?? null);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function rdToWgs84(float $x, float $y): array
    {
        $referenceX = 155000.0;
        $referenceY = 463000.0;
        $referenceLatitude = 52.15517440;
        $referenceLongitude = 5.38720621;
        $dX = ($x - $referenceX) * 0.00001;
        $dY = ($y - $referenceY) * 0.00001;

        $latitudeSeconds = $this->polynomial($dX, $dY, [
            [0, 1, 3235.65389],
            [2, 0, -32.58297],
            [0, 2, -0.24750],
            [2, 1, -0.84978],
            [0, 3, -0.06550],
            [2, 2, -0.01709],
            [1, 0, -0.00738],
            [4, 0, 0.00530],
            [2, 3, -0.00039],
            [4, 1, 0.00033],
            [1, 1, -0.00012],
        ]);
        $longitudeSeconds = $this->polynomial($dX, $dY, [
            [1, 0, 5260.52916],
            [1, 1, 105.94684],
            [1, 2, 2.45656],
            [3, 0, -0.81885],
            [1, 3, 0.05594],
            [3, 1, -0.05607],
            [0, 1, 0.01199],
            [3, 2, -0.00256],
            [1, 4, 0.00128],
            [0, 2, 0.00022],
            [2, 0, -0.00022],
            [5, 0, 0.00026],
        ]);

        return [
            round($referenceLongitude + ($longitudeSeconds / 3600), 7),
            round($referenceLatitude + ($latitudeSeconds / 3600), 7),
        ];
    }

    /**
     * @param list<array{0: int, 1: int, 2: float}> $terms
     */
    private function polynomial(float $dX, float $dY, array $terms): float
    {
        $result = 0.0;
        foreach ($terms as [$xPower, $yPower, $coefficient]) {
            $result += $coefficient * ($dX ** $xPower) * ($dY ** $yPower);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenFeatureInfo(mixed $featureInfo): array
    {
        $properties = [];
        $this->collectAttributes($featureInfo, $properties);

        return $properties;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function collectAttributes(mixed $value, array &$properties): void
    {
        if (! is_array($value)) {
            return;
        }

        $displayName = $value['DisplayName'] ?? null;
        $attributeName = $value['Attribute'] ?? null;
        if (is_string($displayName) || is_string($attributeName)) {
            $key = trim(is_string($displayName) && $displayName !== '' ? $displayName : (string) $attributeName);
            if ($key !== '') {
                $properties[$key] = $value['Value'] ?? $value['DefaultValue'] ?? null;
            }
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $this->collectAttributes($child, $properties);
            }
        }
    }

    /**
     * @return array{category: string, severity: string, title: string, summary: string|null}|null
     */
    private function classifyFeature(array $feature, int $layerId): ?array
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        if ($layerId === self::NOTAM_LAYER_ID) {
            if (! $this->hasNotamLocation($feature)) {
                return null;
            }

            return [
                'category' => 'notam',
                'severity' => 'notice',
                'title' => $this->propertyString($properties, ['NOTAM nummer', 'number']) ?? 'NOTAM',
                'summary' => $this->propertyString($properties, ['Beschrijving', 'text']),
            ];
        }

        if ($layerId === self::NATURA2000_LAYER_ID) {
            return [
                'category' => 'natura2000',
                'severity' => 'warning',
                'title' => $this->propertyString($properties, ['Naam']) ?? 'Natura 2000',
                'summary' => $this->propertyString($properties, ['Beschrijving']),
            ];
        }

        if ($layerId === self::VITAL_INFRA_LAYER_ID) {
            return [
                'category' => 'vital_infra',
                'severity' => 'restricted',
                'title' => $this->propertyString($properties, ['Type']) ?? 'Vitale infrastructuur',
                'summary' => $this->propertyString($properties, ['Beschrijving']),
            ];
        }

        $class = $this->propertyString($properties, ['Klasse']) ?? '';
        $airspace = $this->propertyString($properties, ['Luchtruim']) ?? '';
        $categories = implode(' ', array_filter([
            $this->propertyString($properties, ['Categorie A1']),
            $this->propertyString($properties, ['Categorie A2']),
            $this->propertyString($properties, ['Categorie A3']),
        ]));

        if ($this->containsAny($airspace, ['Laagvlieggebied Mil.', 'Laagvliegroute Mil.', 'Laagvliegroute Zweefvliegtuigen'])) {
            return [
                'category' => 'low_flying',
                'severity' => 'warning',
                'title' => $this->propertyString($properties, ['Naam', 'Afkorting']) ?? 'Laagvlieggebied',
                'summary' => $airspace,
            ];
        }

        if (
            $this->containsAny($class, ['Prohibited', 'Verboden', 'Restricted', 'Beperkt', 'Danger', 'Gevaar'])
            || $this->containsAny($categories, ['Verboden'])
            || $this->containsAny($airspace, ['CTR', 'CTR Mil.', 'EHP_EHR_EHD', 'EHP', 'EHR', 'EHD', 'TGB', 'TRA', 'TSA'])
        ) {
            return [
                'category' => 'no_fly',
                'severity' => 'restricted',
                'title' => $this->propertyString($properties, ['Naam', 'Afkorting']) ?? 'Luchtruimbeperking',
                'summary' => trim($airspace.' '.$class) ?: null,
            ];
        }

        return null;
    }

    private function isActiveNotam(array $feature): bool
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $active = $this->propertyString($properties, ['Actief', 'active']);

        return $active === null || in_array(strtolower(trim($active)), ['1', 'true', 'ja', 'yes', 'active'], true);
    }

    private function isDroneRelevantNotam(array $feature): bool
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $scope = $this->propertyString($properties, ['Scope', 'scope']) ?? '';
        $series = $this->propertyString($properties, ['Series', 'series']) ?? '';
        $description = $this->propertyString($properties, ['Beschrijving', 'text']) ?? '';
        $searchText = trim($scope.' '.$series.' '.$description);

        if ($searchText === '') {
            return false;
        }

        if ($this->containsAny($scope, ['Air Traffic Management', 'Aerodromes', 'Communications warnings'])) {
            return false;
        }

        return $this->containsAny($searchText, [
            'Navigation warnings',
            'Unmanned aircraft',
            'UAS',
            'DRONE',
            'MODEL FLYING',
            'FIREWORKS',
            'PJE',
            'PARACHUTE',
            'AIR DISPLAY',
            'OBSTACLE',
            'CRANE',
            'KITE',
            'BALLOON',
        ]);
    }

    private function hasNotamLocation(array $feature): bool
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

        return $this->numericProperty($properties, ['Lat', 'lat']) !== null
            && $this->numericProperty($properties, ['Lon', 'lon']) !== null;
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $keys
     */
    private function propertyString(array $properties, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $properties[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $value, array $needles): bool
    {
        $normalized = strtolower($value);
        foreach ($needles as $needle) {
            if (str_contains($normalized, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function distanceToGeometry(mixed $geometry, float $latitude, float $longitude): ?float
    {
        if (! is_array($geometry) || ! is_string($geometry['type'] ?? null)) {
            return null;
        }

        return match ($geometry['type']) {
            'Point' => $this->distanceToPoint($geometry['coordinates'] ?? null, $latitude, $longitude),
            'MultiPoint', 'LineString' => $this->distanceToLine($geometry['coordinates'] ?? null, $latitude, $longitude),
            'MultiLineString' => $this->distanceToLines($geometry['coordinates'] ?? null, $latitude, $longitude),
            'Polygon' => $this->distanceToPolygon($geometry['coordinates'] ?? null, $latitude, $longitude),
            'MultiPolygon' => $this->distanceToPolygons($geometry['coordinates'] ?? null, $latitude, $longitude),
            default => null,
        };
    }

    private function distanceToNotamLocation(array $feature, float $latitude, float $longitude): ?float
    {
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $notamLatitude = $this->numericProperty($properties, ['Lat', 'lat']);
        $notamLongitude = $this->numericProperty($properties, ['Lon', 'lon']);
        if ($notamLatitude === null || $notamLongitude === null) {
            return null;
        }

        $radiusMeters = max(0.0, $this->numericProperty($properties, ['Radius', 'radius']) ?? 0.0);
        $radiusMeters = $this->notamRadiusMeters($properties, $radiusMeters);
        $centerDistance = $this->distanceToPoint([$notamLongitude, $notamLatitude], $latitude, $longitude);
        if ($centerDistance === null) {
            return null;
        }

        return max(0.0, $centerDistance - $radiusMeters);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function notamRadiusMeters(array $properties, float $defaultRadius): float
    {
        $description = $this->propertyString($properties, ['Beschrijving', 'text']) ?? '';
        if (preg_match('/RADIUS\s+([0-9]+(?:[.,][0-9]+)?)\s*NM\b/i', $description, $matches) === 1) {
            return (float) str_replace(',', '.', $matches[1]) * 1852.0;
        }

        if (preg_match('/RADIUS\s+([0-9]+(?:[.,][0-9]+)?)\s*M\b/i', $description, $matches) === 1) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        return $defaultRadius;
    }

    private function distanceToPoint(mixed $coordinate, float $latitude, float $longitude): ?float
    {
        if (! $this->isLonLatPair($coordinate)) {
            return null;
        }

        [$x, $y] = $this->toLocalMeters((float) $coordinate[1], (float) $coordinate[0], $latitude, $longitude);

        return sqrt(($x ** 2) + ($y ** 2));
    }

    private function distanceToLine(mixed $coordinates, float $latitude, float $longitude): ?float
    {
        if (! is_array($coordinates) || count($coordinates) === 0) {
            return null;
        }

        $points = [];
        foreach ($coordinates as $coordinate) {
            if ($this->isLonLatPair($coordinate)) {
                $points[] = $this->toLocalMeters((float) $coordinate[1], (float) $coordinate[0], $latitude, $longitude);
            }
        }

        return $this->distanceToLocalPoints($points);
    }

    private function distanceToLines(mixed $lines, float $latitude, float $longitude): ?float
    {
        if (! is_array($lines)) {
            return null;
        }

        $minimum = null;
        foreach ($lines as $line) {
            $distance = $this->distanceToLine($line, $latitude, $longitude);
            if ($distance !== null) {
                $minimum = $minimum === null ? $distance : min($minimum, $distance);
            }
        }

        return $minimum;
    }

    private function distanceToPolygons(mixed $polygons, float $latitude, float $longitude): ?float
    {
        if (! is_array($polygons)) {
            return null;
        }

        $minimum = null;
        foreach ($polygons as $polygon) {
            $distance = $this->distanceToPolygon($polygon, $latitude, $longitude);
            if ($distance !== null) {
                $minimum = $minimum === null ? $distance : min($minimum, $distance);
            }
        }

        return $minimum;
    }

    private function distanceToPolygon(mixed $rings, float $latitude, float $longitude): ?float
    {
        if (! is_array($rings) || count($rings) === 0) {
            return null;
        }

        $localRings = [];
        foreach ($rings as $ring) {
            if (! is_array($ring)) {
                continue;
            }

            $points = [];
            foreach ($ring as $coordinate) {
                if ($this->isLonLatPair($coordinate)) {
                    $points[] = $this->toLocalMeters((float) $coordinate[1], (float) $coordinate[0], $latitude, $longitude);
                }
            }
            if ($points !== []) {
                $localRings[] = $points;
            }
        }

        if ($localRings === []) {
            return null;
        }

        if ($this->pointInPolygon([0.0, 0.0], $localRings)) {
            return 0.0;
        }

        $minimum = null;
        foreach ($localRings as $ring) {
            $distance = $this->distanceToLocalPoints($ring);
            if ($distance !== null) {
                $minimum = $minimum === null ? $distance : min($minimum, $distance);
            }
        }

        return $minimum;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     */
    private function distanceToLocalPoints(array $points): ?float
    {
        if ($points === []) {
            return null;
        }

        $minimum = null;
        $count = count($points);
        for ($index = 0; $index < $count; $index++) {
            $current = $points[$index];
            $next = $points[($index + 1) % $count] ?? null;
            $pointDistance = sqrt(($current[0] ** 2) + ($current[1] ** 2));
            $minimum = $minimum === null ? $pointDistance : min($minimum, $pointDistance);

            if ($next !== null && $index < $count - 1) {
                $segmentDistance = $this->distanceToSegment([0.0, 0.0], $current, $next);
                $minimum = min($minimum, $segmentDistance);
            }
        }

        return $minimum;
    }

    /**
     * @param array{0: float, 1: float} $point
     * @param array{0: float, 1: float} $start
     * @param array{0: float, 1: float} $end
     */
    private function distanceToSegment(array $point, array $start, array $end): float
    {
        $dx = $end[0] - $start[0];
        $dy = $end[1] - $start[1];
        if ($dx === 0.0 && $dy === 0.0) {
            return sqrt((($point[0] - $start[0]) ** 2) + (($point[1] - $start[1]) ** 2));
        }

        $t = max(0.0, min(1.0, ((($point[0] - $start[0]) * $dx) + (($point[1] - $start[1]) * $dy)) / (($dx ** 2) + ($dy ** 2))));
        $projection = [$start[0] + ($t * $dx), $start[1] + ($t * $dy)];

        return sqrt((($point[0] - $projection[0]) ** 2) + (($point[1] - $projection[1]) ** 2));
    }

    /**
     * @param array{0: float, 1: float} $point
     * @param list<list<array{0: float, 1: float}>> $rings
     */
    private function pointInPolygon(array $point, array $rings): bool
    {
        $insideOuter = $this->pointInRing($point, $rings[0] ?? []);
        if (! $insideOuter) {
            return false;
        }

        foreach (array_slice($rings, 1) as $hole) {
            if ($this->pointInRing($point, $hole)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{0: float, 1: float} $point
     * @param list<array{0: float, 1: float}> $ring
     */
    private function pointInRing(array $point, array $ring): bool
    {
        $inside = false;
        $count = count($ring);
        if ($count < 3) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $ring[$i][0];
            $yi = $ring[$i][1];
            $xj = $ring[$j][0];
            $yj = $ring[$j][1];

            $intersects = (($yi > $point[1]) !== ($yj > $point[1]))
                && ($point[0] < (($xj - $xi) * ($point[1] - $yi) / (($yj - $yi) ?: 0.0000001)) + $xi);
            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function toLocalMeters(float $latitude, float $longitude, float $originLatitude, float $originLongitude): array
    {
        $x = ($longitude - $originLongitude) * 111320 * cos(deg2rad($originLatitude));
        $y = ($latitude - $originLatitude) * 110574;

        return [$x, $y];
    }

    private function isLonLatPair(mixed $coordinate): bool
    {
        return is_array($coordinate)
            && count($coordinate) >= 2
            && is_numeric($coordinate[0] ?? null)
            && is_numeric($coordinate[1] ?? null);
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $keys
     */
    private function numericProperty(array $properties, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $properties[$key] ?? null;
            if (is_string($value)) {
                $value = str_replace(',', '.', trim($value));
            }
            if (is_numeric($value)) {
                $number = (float) $value;

                return is_finite($number) ? $number : null;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $features
     * @return array<string, int>
     */
    private function countsByCategory(array $features): array
    {
        $counts = ['notam' => 0, 'no_fly' => 0, 'low_flying' => 0, 'natura2000' => 0, 'vital_infra' => 0];
        foreach ($features as $feature) {
            $category = $feature['properties']['_aeret']['category'] ?? null;
            if (is_string($category)) {
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    private function emptyCollection(int $layerId, int $startId, int $endId): array
    {
        return [
            'type' => 'FeatureCollection',
            'features' => [],
            'meta' => [
                'layer_id' => $layerId,
                'start_id' => $startId,
                'end_id' => $endId,
                'feature_count' => 0,
                'source' => 'Aeret KaartViewer Drone PreFlight Basic',
            ],
        ];
    }

    private function featureUrl(int $layerId, int $objectId): string
    {
        return self::BASE_URL."/{$layerId}/info/{$objectId}";
    }

    private function isAllowedLayer(int $layerId): bool
    {
        return in_array($layerId, [834, 855, 899, 1391, 1420], true);
    }

    private function layerName(int $layerId): string
    {
        return match ($layerId) {
            834 => 'Aeret Airspace Open',
            855 => 'Aeret NOTAM feed',
            899 => 'Natura2000 Basic',
            1391 => 'Buffer tot Vit. Infra',
            1420 => 'Aeret NOTAM Feed V2',
            default => 'Aeret laag',
        };
    }

    private function featureCacheTtl(int $layerId): \DateTimeInterface
    {
        return $layerId === self::NOTAM_LAYER_ID ? now()->addMinutes(5) : now()->addMinutes(10);
    }

    private function layerCacheTtl(int $layerId): \DateTimeInterface
    {
        return $layerId === self::NOTAM_LAYER_ID ? now()->addMinutes(5) : now()->addMinutes(10);
    }
}
