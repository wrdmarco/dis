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
        $base = [
            'generated_at' => now()->toIso8601String(),
            'location' => [
                'label' => $locationLabel,
                'latitude' => round($latitude, 7),
                'longitude' => round($longitude, 7),
            ],
            'checklist' => $this->checklist(),
        ];

        try {
            return $base + [
                'map' => $this->mapData($latitude, $longitude),
                'airspace' => $this->airspaceData($latitude, $longitude),
                'weather' => $this->weatherData($latitude, $longitude),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $base + [
                'map' => $this->fallbackMapData($latitude, $longitude, $exception->getMessage()),
                'airspace' => $this->airspaceUnavailable($exception->getMessage()),
                'weather' => $this->weatherUnavailable($exception->getMessage()),
            ];
        }
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

        return [
            'provider' => 'Aeret Drone PreFlight',
            'status' => $aeretMapUrl !== '' ? 'linked' : 'not_configured',
            'aeret_url' => $this->aeretUrlWithCoordinates($aeretMapUrl, $latitude, $longitude),
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
    private function fallbackMapData(float $latitude, float $longitude, string $error): array
    {
        return [
            'provider' => 'Aeret Drone PreFlight',
            'status' => 'unavailable',
            'aeret_url' => null,
            'openstreetmap_url' => sprintf(
                'https://www.openstreetmap.org/?mlat=%1$.6f&mlon=%2$.6f#map=16/%1$.6f/%2$.6f',
                $latitude,
                $longitude,
            ),
            'errors' => [$error],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function airspaceData(float $latitude, float $longitude): array
    {
        $apiUrl = SystemSetting::string('drone.aeret_api_url', is_string(config('dis.drone_flight.aeret_api_url')) ? (string) config('dis.drone_flight.aeret_api_url') : null);

        if (! is_string($apiUrl) || trim($apiUrl) === '') {
            return $this->airspaceUnavailable('AERET_API_URL ontbreekt.', 'not_configured', 'Aeret API endpoint is niet geconfigureerd. Controleer de Aeret dronekaart en NOTAM handmatig voor inzet.');
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
                return $this->airspaceUnavailable('Aeret API HTTP '.$response->status());
            }

            $payload = $response->json();

            return [
                'provider' => 'Aeret Drone PreFlight',
                'status' => 'available',
                'summary' => $this->stringValue($payload, ['summary', 'message']) ?? 'Aeret/NOTAM gegevens opgehaald.',
                'no_fly_zones' => $this->listValue($payload, ['no_fly_zones', 'nofly_zones', 'zones']),
                'notams' => $this->listValue($payload, ['notams', 'notam']),
                'restrictions' => $this->listValue($payload, ['restrictions', 'airspace', 'warnings']),
                'raw' => $payload,
                'errors' => [],
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->airspaceUnavailable($exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function airspaceUnavailable(string $error, string $status = 'unavailable', string $summary = 'Aeret/NOTAM gegevens konden niet worden opgehaald.'): array
    {
        return [
            'provider' => 'Aeret Drone PreFlight',
            'status' => $status,
            'summary' => $summary,
            'no_fly_zones' => [],
            'notams' => [],
            'restrictions' => [],
            'errors' => [$error],
        ];
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

        unset($query['@dpf_basic']);
        $query = array_merge($query, [
            'catalogus' => '1',
            'v' => '5',
            'website' => 'dpf_basic',
            'x' => round($x, 2),
            'y' => round($y, 2),
            'zoom' => '9',
        ]);

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'aeret.kaartviewer.nl';
        $path = $parts['path'] ?? '/';

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $scheme.'://'.$host.$path.'?@dpf_basic'.($queryString === '' ? '' : '&'.$queryString);
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
