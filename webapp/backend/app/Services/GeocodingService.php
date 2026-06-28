<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

final class GeocodingService
{
    /**
     * @return array{latitude: string, longitude: string}|null
     */
    public function coordinatesFor(?string $locationLabel): ?array
    {
        $query = trim((string) $locationLabel);
        if ($query === '' || ! (bool) config('dis.geocoding.enabled', true)) {
            return null;
        }

        $provider = (string) config('dis.geocoding.provider', 'nominatim');
        if ($provider !== 'nominatim') {
            return null;
        }

        return $this->nominatimCoordinatesFor($query);
    }

    /**
     * @return array{latitude: string, longitude: string}|null
     */
    private function nominatimCoordinatesFor(string $query): ?array
    {
        $url = trim((string) config('dis.geocoding.nominatim_url', 'https://nominatim.openstreetmap.org/search'));
        if ($url === '') {
            return null;
        }

        try {
            $parameters = [
                'q' => $query,
                'format' => 'jsonv2',
                'limit' => 1,
            ];

            $countryCodes = trim((string) config('dis.geocoding.country_codes', ''));
            if ($countryCodes !== '') {
                $parameters['countrycodes'] = $countryCodes;
            }

            $response = Http::timeout(8)
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => $this->userAgent(),
                ])
                ->get($url, $parameters);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! is_array($payload[0] ?? null)) {
                return null;
            }

            $latitude = $this->coordinate($payload[0]['lat'] ?? null, -90, 90);
            $longitude = $this->coordinate($payload[0]['lon'] ?? null, -180, 180);

            if ($latitude === null || $longitude === null) {
                return null;
            }

            return [
                'latitude' => number_format($latitude, 7, '.', ''),
                'longitude' => number_format($longitude, 7, '.', ''),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function userAgent(): string
    {
        $configured = trim((string) config('dis.geocoding.user_agent', ''));
        if ($configured !== '') {
            return $configured;
        }

        return trim((string) config('app.url', 'D.I.S')).' D.I.S Geocoder';
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;
        if (! is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
            return null;
        }

        return $coordinate;
    }
}
