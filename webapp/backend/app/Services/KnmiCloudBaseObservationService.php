<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class KnmiCloudBaseObservationService
{
    private const CACHE_NAMESPACE = 'wallboard:uav-forecast:knmi-cloud-base:v1';

    private const COLLECTION_URL = 'https://api.dataplatform.knmi.nl/edr/v1/collections/10-minute-in-situ-meteorological-observations';

    private const ATTRIBUTION = 'KNMI';

    private const PERIOD_MINUTES = 30;

    private const HEIGHT_REFERENCE = 'mean_sea_level';

    private const QUERY_WINDOW_MINUTES = 40;

    private const NETHERLANDS_BBOX = '3.2,50.7,7.3,53.7';

    /** @var list<string> */
    private const PARAMETERS = [
        'h1',
        'h2',
        'h3',
        'hc1',
        'hc2',
        'hc3',
        'n1',
        'n2',
        'n3',
        'nc1',
        'nc2',
        'nc3',
    ];

    public function __construct(
        private readonly KnmiEdrConfiguration $configuration,
    ) {}

    /**
     * A single KNMI station is meaningful only for an address forecast. The
     * national forecast is an average of twelve province reference points and
     * must never imply that one point observation represents the Netherlands.
     *
     * @param  array<string, mixed>  $resolution
     * @return array{
     *     status: 'measured'|'no_cloud_detected'|'unknown',
     *     base_height_m: int|null,
     *     height_reference: string,
     *     layers: list<array{height_m: int, cover_okta: int|null}>,
     *     station: array{id: string, name: string, distance_km: float}|null,
     *     observed_at: string|null,
     *     period_minutes: int,
     *     attribution: string
     * }
     */
    public function forResolution(array $resolution): array
    {
        if (($resolution['mode'] ?? null) !== WallboardForecastLocationService::MODE_ADDRESS
            || ! ($resolution['complete'] ?? false)
            || count((array) ($resolution['locations'] ?? [])) !== 1) {
            return $this->unknown();
        }

        $location = $resolution['locations'][0] ?? null;
        if (! is_array($location)
            || ! is_numeric($location['latitude'] ?? null)
            || ! is_numeric($location['longitude'] ?? null)) {
            return $this->unknown();
        }

        $latitude = (float) $location['latitude'];
        $longitude = (float) $location['longitude'];
        if (! $this->validCoordinates($latitude, $longitude) || $this->configuration->apiKey() === null) {
            return $this->unknown();
        }

        try {
            $stations = $this->stations();
            $observations = $this->observations();
        } catch (Throwable) {
            return $this->unknown();
        }

        $candidates = [];
        foreach ($observations as $observation) {
            $station = $stations[$observation['station_id']] ?? null;
            if (! is_array($station)) {
                continue;
            }

            try {
                $observedAt = CarbonImmutable::parse($observation['observed_at'])->utc();
            } catch (Throwable) {
                continue;
            }
            if (! $this->isCurrent($observedAt)) {
                continue;
            }

            $distanceKm = $this->distanceKm(
                $latitude,
                $longitude,
                $station['latitude'],
                $station['longitude'],
            );
            if (! is_finite($distanceKm)) {
                continue;
            }

            $candidates[] = [
                ...$observation,
                'station' => $station,
                'distance_km' => $distanceKm,
                'observed_at_value' => $observedAt,
            ];
        }

        usort($candidates, static function (array $first, array $second): int {
            $distance = $first['distance_km'] <=> $second['distance_km'];
            if ($distance !== 0) {
                return $distance;
            }

            return $second['observed_at_value'] <=> $first['observed_at_value'];
        });

        $candidate = $candidates[0] ?? null;
        if (! is_array($candidate)
            || $candidate['distance_km'] > $this->positiveFloatConfig('cloud_base_max_distance_km', 30.0)) {
            return $this->unknown();
        }

        return [
            'status' => $candidate['status'],
            'base_height_m' => $candidate['base_height_m'],
            'height_reference' => self::HEIGHT_REFERENCE,
            'layers' => $candidate['layers'],
            'station' => [
                'id' => $candidate['station']['id'],
                'name' => $candidate['station']['name'],
                'distance_km' => round($candidate['distance_km'], 1),
            ],
            'observed_at' => $candidate['observed_at_value']->toIso8601String(),
            'period_minutes' => self::PERIOD_MINUTES,
            'attribution' => self::ATTRIBUTION,
        ];
    }

    /**
     * @return array<string, array{id: string, name: string, latitude: float, longitude: float}>
     */
    private function stations(): array
    {
        return $this->cached(
            self::CACHE_NAMESPACE.':stations',
            $this->positiveIntConfig('cloud_base_station_cache_seconds', 86400),
            fn (): array => $this->fetchStations(),
        );
    }

    /**
     * @return list<array{
     *     station_id: string,
     *     status: 'measured'|'no_cloud_detected',
     *     base_height_m: int|null,
     *     layers: list<array{height_m: int, cover_okta: int|null}>,
     *     observed_at: string
     * }>
     */
    private function observations(): array
    {
        return $this->cached(
            self::CACHE_NAMESPACE.':observations',
            $this->positiveIntConfig('cache_seconds', 900),
            fn (): array => $this->fetchObservations(),
        );
    }

    /**
     * @return array<string, array{id: string, name: string, latitude: float, longitude: float}>
     */
    private function fetchStations(): array
    {
        $response = $this->request()->get(self::COLLECTION_URL.'/locations', [
            'bbox' => self::NETHERLANDS_BBOX,
            'datetime' => $this->queryWindow(),
        ]);
        $payload = $this->validatedJson($response->successful(), $response->body(), $response->json());
        if (($payload['type'] ?? null) !== 'FeatureCollection'
            || ! is_array($payload['features'] ?? null)
            || ! array_is_list($payload['features'])
            || count($payload['features']) > 500) {
            throw new \UnexpectedValueException('KNMI station metadata response invalid.');
        }

        $stations = [];
        foreach ($payload['features'] as $feature) {
            if (! is_array($feature)
                || ($feature['type'] ?? null) !== 'Feature'
                || ! is_string($feature['id'] ?? null)
                || ! is_array($feature['geometry'] ?? null)
                || ($feature['geometry']['type'] ?? null) !== 'Point'
                || ! is_array($feature['geometry']['coordinates'] ?? null)
                || ! is_array($feature['properties'] ?? null)) {
                continue;
            }

            $id = trim($feature['id']);
            $name = is_string($feature['properties']['name'] ?? null)
                ? trim($feature['properties']['name'])
                : '';
            $longitude = $feature['geometry']['coordinates'][0] ?? null;
            $latitude = $feature['geometry']['coordinates'][1] ?? null;
            if ($id === '' || strlen($id) > 128
                || $name === '' || mb_strlen($name) > 160
                || ! is_numeric($latitude) || ! is_numeric($longitude)
                || ! $this->validCoordinates((float) $latitude, (float) $longitude)) {
                continue;
            }

            $stations[$id] = [
                'id' => $id,
                'name' => $name,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];
        }

        if ($stations === []) {
            throw new \UnexpectedValueException('KNMI station metadata contains no valid stations.');
        }

        return $stations;
    }

    /**
     * @return list<array{
     *     station_id: string,
     *     status: 'measured'|'no_cloud_detected',
     *     base_height_m: int|null,
     *     layers: list<array{height_m: int, cover_okta: int|null}>,
     *     observed_at: string
     * }>
     */
    private function fetchObservations(): array
    {
        $response = $this->request()
            ->accept('application/prs.coverage+json')
            ->get(self::COLLECTION_URL.'/cube', [
                'f' => 'CoverageJSON',
                'bbox' => self::NETHERLANDS_BBOX,
                'datetime' => $this->queryWindow(),
                'parameter-name' => implode(',', self::PARAMETERS),
            ]);
        $payload = $this->validatedJson($response->successful(), $response->body(), $response->json());
        if (($payload['type'] ?? null) !== 'CoverageCollection'
            || ! is_array($payload['coverages'] ?? null)
            || ! array_is_list($payload['coverages'])
            || count($payload['coverages']) > 500) {
            throw new \UnexpectedValueException('KNMI cloud-base response invalid.');
        }

        $observations = [];
        foreach ($payload['coverages'] as $coverage) {
            $observation = is_array($coverage) ? $this->parseCoverage($coverage) : null;
            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        if ($observations === []) {
            throw new \UnexpectedValueException('KNMI cloud-base response contains no valid observations.');
        }

        return $observations;
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @return array{
     *     station_id: string,
     *     status: 'measured'|'no_cloud_detected',
     *     base_height_m: int|null,
     *     layers: list<array{height_m: int, cover_okta: int|null}>,
     *     observed_at: string
     * }|null
     */
    private function parseCoverage(array $coverage): ?array
    {
        $stationId = $coverage['eumetnet:locationId'] ?? null;
        $domain = $coverage['domain'] ?? null;
        $times = is_array($domain) ? ($domain['axes']['t']['values'] ?? null) : null;
        $xValues = is_array($domain) ? ($domain['axes']['x']['values'] ?? null) : null;
        $yValues = is_array($domain) ? ($domain['axes']['y']['values'] ?? null) : null;
        $ranges = $coverage['ranges'] ?? null;
        if (($coverage['type'] ?? null) !== 'Coverage'
            || ! is_array($domain)
            || ($domain['type'] ?? null) !== 'Domain'
            || ($domain['domainType'] ?? null) !== 'PointSeries'
            || ! is_string($stationId) || trim($stationId) === '' || strlen($stationId) > 128
            || ! is_array($times) || ! array_is_list($times) || $times === [] || count($times) > 1000
            || ! is_array($xValues) || ! array_is_list($xValues) || count($xValues) !== 1 || ! is_numeric($xValues[0] ?? null)
            || ! is_array($yValues) || ! array_is_list($yValues) || count($yValues) !== 1 || ! is_numeric($yValues[0] ?? null)
            || ! $this->validCoordinates((float) $yValues[0], (float) $xValues[0])
            || ! is_array($ranges)) {
            return null;
        }

        $orderedTimes = [];
        foreach ($times as $index => $time) {
            if (! is_string($time)) {
                continue;
            }
            try {
                $orderedTimes[] = ['index' => $index, 'time' => CarbonImmutable::parse($time)->utc()];
            } catch (Throwable) {
                continue;
            }
        }
        usort($orderedTimes, static fn (array $first, array $second): int => $second['time'] <=> $first['time']);

        foreach ($orderedTimes as $time) {
            // Prefer the direct ceilometer set and fall back to the processed
            // station cloud-layer set when the direct fields are unavailable.
            $layers = $this->layerSet($ranges, $time['index'], count($times), 'hc', 'nc')
                ?? $this->layerSet($ranges, $time['index'], count($times), 'h', 'n');
            if ($layers === null) {
                continue;
            }

            return [
                'station_id' => trim($stationId),
                'status' => $layers === [] ? 'no_cloud_detected' : 'measured',
                'base_height_m' => $layers === [] ? null : $layers[0]['height_m'],
                'layers' => $layers,
                'observed_at' => $time['time']->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * Cloud layer height and amount are one KNMI value set. A 0-foot/0-okta
     * pair means that no cloud was measured at that layer; mismatched or
     * out-of-range pairs are rejected instead of being interpreted optimistically.
     *
     * @param  array<string, mixed>  $ranges
     * @return list<array{height_m: int, cover_okta: int|null}>|null
     */
    private function layerSet(
        array $ranges,
        int $timeIndex,
        int $timeCount,
        string $heightPrefix,
        string $coverPrefix,
    ): ?array {
        $layers = [];
        $hasPair = false;
        for ($layer = 1; $layer <= 3; $layer++) {
            $heightFeet = $this->rangeValue($ranges, $heightPrefix.$layer, $timeIndex, $timeCount);
            $coverOkta = $this->rangeValue($ranges, $coverPrefix.$layer, $timeIndex, $timeCount);
            if ($heightFeet === null && $coverOkta === null) {
                continue;
            }
            if ($heightFeet === null || $coverOkta === null
                || $heightFeet < 0 || $heightFeet > 60000
                || $coverOkta < 0 || $coverOkta > 8
                || floor($coverOkta) !== $coverOkta) {
                return null;
            }

            $hasPair = true;
            if ($heightFeet === 0.0 && $coverOkta === 0.0) {
                continue;
            }
            if ($heightFeet <= 0.0 || $coverOkta <= 0.0) {
                return null;
            }

            $layers[] = [
                'height_m' => (int) round($heightFeet * 0.3048),
                'cover_okta' => (int) $coverOkta,
            ];
        }

        if (! $hasPair) {
            return null;
        }

        usort($layers, static fn (array $first, array $second): int => $first['height_m'] <=> $second['height_m']);

        return $layers;
    }

    /** @param array<string, mixed> $ranges */
    private function rangeValue(array $ranges, string $parameter, int $timeIndex, int $timeCount): ?float
    {
        $range = $ranges[$parameter] ?? null;
        if (! is_array($range)) {
            return null;
        }
        $values = $range['values'] ?? null;
        if (($range['type'] ?? null) !== 'NdArray'
            || ! in_array($range['dataType'] ?? null, ['float', 'integer'], true)
            || ($range['axisNames'] ?? null) !== ['t', 'y', 'x']
            || ($range['shape'] ?? null) !== [$timeCount, 1, 1]
            || ! is_array($values)
            || ! array_is_list($values)
            || count($values) !== $timeCount) {
            return null;
        }
        $value = $values[$timeIndex] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @template T of array
     *
     * @param  callable(): T  $loader
     * @return T
     */
    private function cached(string $key, int $freshSeconds, callable $loader): array
    {
        $fresh = Cache::get($key.':fresh');
        if (is_array($fresh)) {
            return $fresh;
        }

        try {
            $value = $loader();
            Cache::put($key.':fresh', $value, $freshSeconds);
            Cache::put(
                $key.':last-good',
                $value,
                max($freshSeconds * 2, $this->positiveIntConfig('last_good_cache_seconds', 21600)),
            );

            return $value;
        } catch (Throwable $exception) {
            $lastGood = Cache::get($key.':last-good');
            if (is_array($lastGood)) {
                return $lastGood;
            }

            throw $exception;
        }
    }

    private function request(): PendingRequest
    {
        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            throw new \RuntimeException('KNMI EDR is not configured.');
        }

        return Http::connectTimeout($this->positiveIntConfig('connect_timeout_seconds', 2))
            ->timeout($this->positiveIntConfig('timeout_seconds', 5))
            ->withHeaders(['Authorization' => $apiKey]);
    }

    /** @return array<string, mixed> */
    private function validatedJson(bool $successful, string $body, mixed $payload): array
    {
        if (! $successful || strlen($body) > 2097152 || ! is_array($payload)) {
            throw new \UnexpectedValueException('KNMI EDR response invalid.');
        }

        return $payload;
    }

    private function queryWindow(): string
    {
        $now = CarbonImmutable::now()->utc();

        return $now->subMinutes(self::QUERY_WINDOW_MINUTES)->toIso8601String()
            .'/'.$now->toIso8601String();
    }

    private function isCurrent(CarbonImmutable $observedAt): bool
    {
        return ! $observedAt->greaterThan(now()->addMinutes(10))
            && ! $observedAt->lessThan(now()->subSeconds(
                $this->positiveIntConfig('cloud_base_stale_seconds', 1800),
            ));
    }

    private function distanceKm(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $fromLatitudeRadians = deg2rad($fromLatitude);
        $toLatitudeRadians = deg2rad($toLatitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitudeRadians) * cos($toLatitudeRadians) * sin($longitudeDelta / 2) ** 2;

        return 2 * 6371.0 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private function validCoordinates(float $latitude, float $longitude): bool
    {
        return is_finite($latitude) && is_finite($longitude)
            && $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180;
    }

    private function positiveIntConfig(string $key, int $fallback): int
    {
        $value = config("dis.wallboards.uav_forecast.{$key}", $fallback);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }

    private function positiveFloatConfig(string $key, float $fallback): float
    {
        $value = config("dis.wallboards.uav_forecast.{$key}", $fallback);

        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0
            ? (float) $value
            : $fallback;
    }

    /**
     * @return array{
     *     status: 'unknown',
     *     base_height_m: null,
     *     height_reference: string,
     *     layers: list<never>,
     *     station: null,
     *     observed_at: null,
     *     period_minutes: int,
     *     attribution: string
     * }
     */
    private function unknown(): array
    {
        return [
            'status' => 'unknown',
            'base_height_m' => null,
            'height_reference' => self::HEIGHT_REFERENCE,
            'layers' => [],
            'station' => null,
            'observed_at' => null,
            'period_minutes' => self::PERIOD_MINUTES,
            'attribution' => self::ATTRIBUTION,
        ];
    }
}
