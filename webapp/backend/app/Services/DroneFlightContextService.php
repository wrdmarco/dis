<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

final class DroneFlightContextService
{
    /**
     * @return array<string, mixed>
     */
    public function preview(float $latitude, float $longitude, ?string $locationLabel = null): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'location' => [
                'label' => $locationLabel,
                'latitude' => round($latitude, 7),
                'longitude' => round($longitude, 7),
            ],
            'map' => $this->mapData($latitude, $longitude),
            'airspace' => $this->airspaceData($latitude, $longitude),
            'weather' => $this->weatherData($latitude, $longitude),
            'checklist' => $this->checklist(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previewForIncident(Incident $incident): ?array
    {
        $latitude = $this->coordinate($incident->latitude);
        $longitude = $this->coordinate($incident->longitude);

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return $this->preview($latitude, $longitude, $incident->location_label);
    }

    public function refreshIncident(Incident $incident): Incident
    {
        $incident->forceFill([
            'drone_flight_context' => $this->previewForIncident($incident),
        ])->save();

        return $incident->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapData(float $latitude, float $longitude): array
    {
        $aeretMapUrl = $this->aeretMapUrl();
        $notamUrl = SystemSetting::string('drone.notam_url', (string) config('dis.drone_flight.notam_url')) ?? '';

        return [
            'provider' => 'Aeret Drone PreFlight',
            'status' => $aeretMapUrl !== '' ? 'linked' : 'not_configured',
            'aeret_url' => $this->aeretUrlWithCoordinates($aeretMapUrl, $latitude, $longitude),
            'notam_url' => $notamUrl,
            'openstreetmap_url' => sprintf(
                'https://www.openstreetmap.org/?mlat=%1$.6f&mlon=%2$.6f#map=16/%1$.6f/%2$.6f',
                $latitude,
                $longitude,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function airspaceData(float $latitude, float $longitude): array
    {
        $apiUrl = SystemSetting::string('drone.aeret_api_url', is_string(config('dis.drone_flight.aeret_api_url')) ? (string) config('dis.drone_flight.aeret_api_url') : null);
        $kaartviewer = $this->aeretKaartviewerData($latitude, $longitude);

        if (! is_string($apiUrl) || trim($apiUrl) === '') {
            return [
                'provider' => 'Aeret Drone PreFlight',
                'status' => ($kaartviewer['status'] ?? null) === 'available' ? 'partial_available' : 'not_configured',
                'summary' => ($kaartviewer['summary'] ?? null) ?: 'Aeret API endpoint is niet geconfigureerd. Controleer de Aeret dronekaart en NOTAM handmatig voor inzet.',
                'no_fly_zones' => [],
                'notams' => $kaartviewer['notams'] ?? [],
                'restrictions' => [],
                'errors' => array_values(array_filter(['AERET_API_URL ontbreekt.', ...($kaartviewer['errors'] ?? [])])),
            ];
        }

        try {
            $request = Http::timeout(8)
                ->acceptJson()
                ->withQueryParameters([
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'radius_m' => 2500,
                ]);

            $apiKey = SystemSetting::string('drone.aeret_api_key');
            if (is_string($apiKey) && trim($apiKey) !== '') {
                $request = $request->withToken($apiKey);
            }

            $response = $request->get($apiUrl);

            if (! $response->successful()) {
                return [
                    'provider' => 'Aeret Drone PreFlight',
                    'status' => 'unavailable',
                    'summary' => 'Aeret/NOTAM gegevens konden niet worden opgehaald.',
                    'no_fly_zones' => [],
                    'notams' => [],
                    'restrictions' => [],
                    'errors' => ['Aeret API HTTP '.$response->status()],
                ];
            }

            $payload = $response->json();

            return [
                'provider' => 'Aeret Drone PreFlight',
                'status' => 'available',
                'summary' => $this->stringValue($payload, ['summary', 'message']) ?? 'Aeret/NOTAM gegevens opgehaald.',
                'no_fly_zones' => $this->listValue($payload, ['no_fly_zones', 'nofly_zones', 'zones']),
                'notams' => $this->mergeLists(
                    $this->listValue($payload, ['notams', 'notam']),
                    $kaartviewer['notams'] ?? [],
                ),
                'restrictions' => $this->listValue($payload, ['restrictions', 'airspace', 'warnings']),
                'raw' => $payload,
                'errors' => $kaartviewer['errors'] ?? [],
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'provider' => 'Aeret Drone PreFlight',
                'status' => 'unavailable',
                'summary' => 'Aeret/NOTAM gegevens konden niet worden opgehaald.',
                'no_fly_zones' => [],
                'notams' => [],
                'restrictions' => [],
                'errors' => [$exception->getMessage()],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function weatherData(float $latitude, float $longitude): array
    {
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'current' => implode(',', [
                        'temperature_2m',
                        'apparent_temperature',
                        'relative_humidity_2m',
                        'precipitation',
                        'rain',
                        'weather_code',
                        'cloud_cover',
                        'wind_speed_10m',
                        'wind_gusts_10m',
                        'wind_direction_10m',
                        'surface_pressure',
                        'visibility',
                    ]),
                    'timezone' => config('app.timezone', 'Europe/Amsterdam'),
                    'forecast_days' => 1,
                ]);

            if (! $response->successful()) {
                return $this->weatherUnavailable('Open-Meteo HTTP '.$response->status());
            }

            $payload = $response->json();
            $current = is_array($payload) && is_array($payload['current'] ?? null) ? $payload['current'] : [];

            return [
                'provider' => 'Open-Meteo',
                'status' => 'available',
                'measured_at' => $current['time'] ?? null,
                'temperature_c' => $this->numericValue($current['temperature_2m'] ?? null),
                'feels_like_c' => $this->numericValue($current['apparent_temperature'] ?? null),
                'humidity_percent' => $this->numericValue($current['relative_humidity_2m'] ?? null),
                'wind_speed_kmh' => $this->numericValue($current['wind_speed_10m'] ?? null),
                'wind_gust_kmh' => $this->numericValue($current['wind_gusts_10m'] ?? null),
                'wind_direction_degrees' => $this->numericValue($current['wind_direction_10m'] ?? null),
                'precipitation_mm' => $this->numericValue($current['precipitation'] ?? null),
                'rain_mm' => $this->numericValue($current['rain'] ?? null),
                'cloud_cover_percent' => $this->numericValue($current['cloud_cover'] ?? null),
                'visibility_m' => $this->numericValue($current['visibility'] ?? null),
                'pressure_hpa' => $this->numericValue($current['surface_pressure'] ?? null),
                'weather_code' => $current['weather_code'] ?? null,
                'summary' => $this->weatherSummary($current),
                'errors' => [],
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->weatherUnavailable($exception->getMessage());
        }
    }

    /**
     * @return array<int, string>
     */
    private function checklist(): array
    {
        return [
            'Controleer Aeret Drone PreFlight op no-fly zones, CTR, Natura 2000, tijdelijke beperkingen en lokale regels.',
            'Controleer actuele NOTAM informatie voor tijdelijke luchtruimbeperkingen.',
            'Beoordeel wind, windstoten, zicht, neerslag, temperatuur en accustatus voor het gekozen dronetype.',
            'Leg piloot, waarnemer, startlocatie, landingslocatie en noodprocedure vast voordat de inzet start.',
        ];
    }

    private function weatherUnavailable(string $error): array
    {
        return [
            'provider' => 'Open-Meteo',
            'status' => 'unavailable',
            'summary' => 'Weerdata kon niet worden opgehaald.',
            'errors' => [$error],
        ];
    }

    /**
     * @param array<string, mixed> $current
     */
    private function weatherSummary(array $current): string
    {
        $temperature = $this->numericValue($current['temperature_2m'] ?? null);
        $wind = $this->numericValue($current['wind_speed_10m'] ?? null);
        $gust = $this->numericValue($current['wind_gusts_10m'] ?? null);
        $visibility = $this->numericValue($current['visibility'] ?? null);

        return trim(sprintf(
            '%s%s%s%s',
            $temperature === null ? '' : 'Temperatuur '.$temperature.' C. ',
            $wind === null ? '' : 'Wind '.$wind.' km/u. ',
            $gust === null ? '' : 'Windstoten '.$gust.' km/u. ',
            $visibility === null ? '' : 'Zicht '.round($visibility / 1000, 1).' km.',
        )) ?: 'Actuele weerdata beschikbaar.';
    }

    private function coordinate(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) ? $coordinate : null;
    }

    private function numericValue(mixed $value): float|int|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? round($number, 1) : null;
    }

    private function urlWithCoordinates(string $url, float $latitude, float $longitude): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query([
            'lat' => round($latitude, 7),
            'lon' => round($longitude, 7),
        ]);
    }

    private function aeretMapUrl(): string
    {
        $configured = SystemSetting::string('drone.aeret_map_url', (string) config('dis.drone_flight.aeret_map_url')) ?? '';
        $configured = trim($configured);

        return $configured === 'https://dronepreflight.nl/' ? 'https://aeret.kaartviewer.nl/?@dpf_basic' : $configured;
    }

    /**
     * @return array{status: string, summary: string, notams: array<int, mixed>, errors: array<int, string>}
     */
    private function aeretKaartviewerData(float $latitude, float $longitude): array
    {
        $baseUrl = $this->aeretMapUrl();
        if ($baseUrl === '' || ! str_contains($baseUrl, 'aeret.kaartviewer.nl')) {
            return [
                'status' => 'not_configured',
                'summary' => '',
                'notams' => [],
                'errors' => ['Aeret kaartviewer URL is niet geconfigureerd.'],
            ];
        }

        try {
            $origin = $this->originFromUrl($baseUrl);
            $website = $this->websiteNameFromAeretUrl($baseUrl);
            $settings = Http::timeout(10)
                ->acceptJson()
                ->get($origin.'/admin/v2/kaartviewerapi/bookmark/'.$website.'/settings')
                ->json();

            if (! is_array($settings) || ! is_numeric($settings['bookmarkID'] ?? null)) {
                return $this->kaartviewerUnavailable('Aeret kaartviewer settings konden niet worden gelezen.');
            }

            $bookmarkId = (int) $settings['bookmarkID'];
            $tree = Http::timeout(10)
                ->acceptJson()
                ->get($origin.'/admin/v2/kaartviewerapi/bookmark/'.$bookmarkId.'/tree')
                ->json();
            $notamNode = $this->findTreeNodeByLabel($tree, 'notam');
            if ($notamNode === null || ! is_numeric($notamNode['ID'] ?? null) || ! is_numeric($notamNode['presentationID'] ?? null)) {
                return $this->kaartviewerUnavailable('Aeret kaartviewer NOTAM-laag is niet gevonden.');
            }

            $bookmarkPresentationId = (int) $notamNode['ID'];
            $presentationId = (int) $notamNode['presentationID'];
            $presentation = Http::timeout(10)
                ->acceptJson()
                ->get($origin.'/admin/v2/kaartviewerapi/bookmark/'.$bookmarkId.'/bookmarkpresentation/'.$bookmarkPresentationId.'/presentation')
                ->json();
            $formId = $this->firstFormId($presentation);
            if ($formId === null) {
                return $this->kaartviewerUnavailable('Aeret kaartviewer NOTAM-formulier is niet gevonden.');
            }

            [$x, $y] = $this->wgs84ToRd($latitude, $longitude);
            $span = 25000;
            $payload = [
                'bbox' => implode(',', [round($x - $span, 2), round($y - $span, 2), round($x + $span, 2), round($y + $span, 2)]),
                'geometry' => ['type' => 'Point', 'coordinates' => [round($x, 2), round($y, 2)]],
                'start' => 0,
                'length' => 50,
            ];
            $data = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post($origin.'/admin/v2/kaartviewerapi/bookmark/'.$bookmarkId.'/domain/1/presentation/'.$presentationId.'/filter/'.$formId.'?request=data&trekking=1', $payload)
                ->json();

            $notams = $this->kaartviewerNotams($data);

            return [
                'status' => 'available',
                'summary' => $notams === []
                    ? 'Aeret kaartviewer NOTAM feed gecontroleerd; geen NOTAM regels ontvangen voor deze locatie.'
                    : 'Aeret kaartviewer NOTAM feed gelezen.',
                'notams' => $notams,
                'errors' => [],
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->kaartviewerUnavailable($exception->getMessage());
        }
    }

    /**
     * @return array{status: string, summary: string, notams: array<int, mixed>, errors: array<int, string>}
     */
    private function kaartviewerUnavailable(string $error): array
    {
        return [
            'status' => 'unavailable',
            'summary' => 'Aeret kaartviewer NOTAM feed kon niet worden gelezen.',
            'notams' => [],
            'errors' => [$error],
        ];
    }

    private function originFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'aeret.kaartviewer.nl';

        return $scheme.'://'.$host;
    }

    private function websiteNameFromAeretUrl(string $url): string
    {
        $parts = parse_url($url);
        $query = [];
        if (is_string($parts['query'] ?? null)) {
            parse_str($parts['query'], $query);
            foreach ($query as $key => $value) {
                if (str_starts_with((string) $key, '@')) {
                    return ltrim((string) $key, '@');
                }
                if ($key === 'website' && is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return 'dpf_basic';
    }

    private function aeretUrlWithCoordinates(string $url, float $latitude, float $longitude): ?string
    {
        if (trim($url) === '') {
            return null;
        }

        if (! str_contains($url, 'aeret.kaartviewer.nl')) {
            return $this->urlWithCoordinates($url, $latitude, $longitude);
        }

        [$x, $y] = $this->wgs84ToRd($latitude, $longitude);
        $parts = parse_url($url);
        $query = [];
        if (is_string($parts['query'] ?? null) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }

        $query = array_merge($query, [
            '@dpf_basic' => $query['@dpf_basic'] ?? '',
            'catalogus' => '1',
            'v' => '5',
            'website' => 'dpf_basic',
            'x' => round($x, 2),
            'y' => round($y, 2),
            'zoom' => '7.5',
        ]);

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'aeret.kaartviewer.nl';
        $path = $parts['path'] ?? '/';

        return $scheme.'://'.$host.$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function wgs84ToRd(float $latitude, float $longitude): array
    {
        $referenceLatitude = 52.15517440;
        $referenceLongitude = 5.38720621;
        $dLatitude = 0.36 * ($latitude - $referenceLatitude);
        $dLongitude = 0.36 * ($longitude - $referenceLongitude);

        $x = 155000
            + (190094.945 * $dLongitude)
            + (-11832.228 * $dLatitude * $dLongitude)
            + (-114.221 * $dLatitude ** 2 * $dLongitude)
            + (-32.391 * $dLongitude ** 3)
            + (-0.705 * $dLatitude)
            + (-2.34 * $dLatitude ** 3 * $dLongitude)
            + (-0.608 * $dLatitude * $dLongitude ** 3)
            + (-0.008 * $dLongitude ** 2)
            + (0.148 * $dLatitude ** 2 * $dLongitude ** 3);

        $y = 463000
            + (309056.544 * $dLatitude)
            + (3638.893 * $dLongitude ** 2)
            + (73.077 * $dLatitude ** 2)
            + (-157.984 * $dLatitude * $dLongitude ** 2)
            + (59.788 * $dLatitude ** 3)
            + (0.433 * $dLongitude)
            + (-6.439 * $dLatitude ** 2 * $dLongitude ** 2)
            + (-0.032 * $dLatitude * $dLongitude)
            + (0.092 * $dLongitude ** 4)
            + (-0.054 * $dLatitude ** 4);

        return [$x, $y];
    }

    /**
     * @param mixed $node
     * @return array<string, mixed>|null
     */
    private function findTreeNodeByLabel(mixed $node, string $needle): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        $label = $node['label'] ?? null;
        if (is_string($label) && str_contains(strtolower($label), strtolower($needle))) {
            return $node;
        }

        $branches = $node['branch'] ?? [];
        if (! is_array($branches)) {
            return null;
        }

        foreach ($branches as $branch) {
            $match = $this->findTreeNodeByLabel($branch, $needle);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @param mixed $presentation
     */
    private function firstFormId(mixed $presentation): ?int
    {
        if (! is_array($presentation)) {
            return null;
        }

        $forms = $presentation['Forms'] ?? [];
        if (! is_array($forms)) {
            return null;
        }

        foreach ($forms as $form) {
            if (is_array($form) && is_numeric($form['ID'] ?? null)) {
                return (int) $form['ID'];
            }
        }

        return null;
    }

    /**
     * @param mixed $payload
     * @return array<int, mixed>
     */
    private function kaartviewerNotams(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        foreach (['features', 'data', 'items', 'results'] as $key) {
            $items = $payload[$key] ?? null;
            if (is_array($items)) {
                return array_values(array_filter(array_map(fn (mixed $item): mixed => $this->normaliseFeatureProperties($item), $items)));
            }
        }

        return [];
    }

    private function normaliseFeatureProperties(mixed $item): mixed
    {
        if (! is_array($item)) {
            return $item;
        }

        $properties = $item['properties'] ?? null;

        return is_array($properties) ? $properties : $item;
    }

    /**
     * @param array<int, mixed> $first
     * @param array<int, mixed> $second
     * @return array<int, mixed>
     */
    private function mergeLists(array $first, array $second): array
    {
        if ($second === []) {
            return $first;
        }
        if ($first === []) {
            return $second;
        }

        return array_values([...$first, ...$second]);
    }

    /**
     * @param array<string, mixed>|mixed $payload
     * @param array<int, string> $keys
     * @return array<int, mixed>
     */
    private function listValue(mixed $payload, array $keys): array
    {
        if (! is_array($payload)) {
            return [];
        }

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_array($value)) {
                return array_values($value);
            }
        }

        foreach (['data', 'results', 'items'] as $containerKey) {
            $container = $payload[$containerKey] ?? null;
            if (is_array($container)) {
                $nested = $this->listValue($container, $keys);
                if ($nested !== []) {
                    return $nested;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed>|mixed $payload
     * @param array<int, string> $keys
     */
    private function stringValue(mixed $payload, array $keys): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
