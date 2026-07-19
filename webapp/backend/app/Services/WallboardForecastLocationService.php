<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class WallboardForecastLocationService
{
    public const MODE_NETHERLANDS = 'netherlands';

    public const MODE_ADDRESS = 'address';

    public const NETHERLANDS_LABEL = 'UAV Nederland';

    public const NETHERLANDS_PROVINCE_COUNT = 12;

    public function __construct(private readonly GeocodingService $geocoding) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     mode: string,
     *     label: string,
     *     locations: list<array{label: string, latitude: float, longitude: float}>,
     *     expected_locations: int,
     *     complete: bool
     * }
     */
    public function resolve(array $options): array
    {
        $mode = (string) ($options['location_mode'] ?? self::MODE_NETHERLANDS);

        if ($mode === self::MODE_ADDRESS) {
            $label = trim((string) ($options['location_label'] ?? ''));
            $coordinates = $this->coordinatesFor($label);

            return [
                'mode' => self::MODE_ADDRESS,
                'label' => $label,
                'locations' => $coordinates === null ? [] : [[
                    'label' => $label,
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                ]],
                'expected_locations' => 1,
                'complete' => $coordinates !== null,
            ];
        }

        $locations = $this->provinceReferencePoints();

        $uniqueCoordinates = array_unique(array_map(
            static fn (array $location): string => sprintf(
                '%.5f,%.5f',
                $location['latitude'],
                $location['longitude'],
            ),
            $locations,
        ));

        return [
            'mode' => self::MODE_NETHERLANDS,
            'label' => self::NETHERLANDS_LABEL,
            'locations' => $locations,
            'expected_locations' => self::NETHERLANDS_PROVINCE_COUNT,
            'complete' => count($locations) === self::NETHERLANDS_PROVINCE_COUNT
                && count($uniqueCoordinates) === self::NETHERLANDS_PROVINCE_COUNT,
        ];
    }

    /**
     * Reject an address configuration before it can be persisted. National pages
     * use managed reference points and need no external lookup here.
     *
     * @param  array<string, mixed>  $configuration
     */
    public function assertResolvableAddresses(array $configuration): void
    {
        foreach ((array) ($configuration['pages'] ?? []) as $index => $page) {
            if (! is_array($page) || ($page['type'] ?? null) !== 'uav_forecast') {
                continue;
            }
            $options = is_array($page['options'] ?? null) ? $page['options'] : [];
            $mode = (string) ($options['location_mode'] ?? (
                array_key_exists('location_label', $options) ? self::MODE_ADDRESS : self::MODE_NETHERLANDS
            ));
            if ($mode !== self::MODE_ADDRESS) {
                continue;
            }
            $resolution = $this->resolve($options);
            if (! $resolution['complete']) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.options.location_label" => [
                        'Het gekozen adres kon niet betrouwbaar worden gevonden. Kies een resultaat uit de adreszoeker.',
                    ],
                ]);
            }
        }
    }

    /** @return array{latitude: float, longitude: float}|null */
    private function coordinatesFor(string $label): ?array
    {
        if ($label === '') {
            return null;
        }

        $key = 'wallboard:uav-forecast:geocode:'.sha1(mb_strtolower($label));
        $cached = Cache::get($key);
        if (is_array($cached) && ($cached['resolved'] ?? true) === false) {
            return null;
        }
        if (is_array($cached)
            && is_numeric($cached['latitude'] ?? null)
            && is_numeric($cached['longitude'] ?? null)) {
            return [
                'latitude' => (float) $cached['latitude'],
                'longitude' => (float) $cached['longitude'],
            ];
        }

        $resolved = $this->geocoding->coordinatesFor($label);
        if ($resolved === null) {
            Cache::put($key, ['resolved' => false], 900);

            return null;
        }

        $coordinates = [
            'latitude' => (float) $resolved['latitude'],
            'longitude' => (float) $resolved['longitude'],
        ];
        Cache::put(
            $key,
            $coordinates,
            max(3600, (int) config('dis.wallboards.uav_forecast.geocode_cache_seconds', 2592000)),
        );

        return $coordinates;
    }

    /** @return list<array{label: string, latitude: float, longitude: float}> */
    private function provinceReferencePoints(): array
    {
        $configured = config('dis.wallboards.uav_forecast.province_reference_points', []);
        if (! is_array($configured) || count($configured) !== self::NETHERLANDS_PROVINCE_COUNT) {
            return [];
        }

        $locations = [];
        $labels = [];
        foreach ($configured as $reference) {
            if (! is_array($reference)
                || ! is_string($reference['label'] ?? null)
                || trim($reference['label']) === ''
                || ! is_numeric($reference['latitude'] ?? null)
                || ! is_numeric($reference['longitude'] ?? null)) {
                return [];
            }
            $location = [
                'label' => trim($reference['label']),
                'latitude' => (float) $reference['latitude'],
                'longitude' => (float) $reference['longitude'],
            ];
            if (! $this->withinNetherlandsBounds($location)) {
                return [];
            }
            $labelKey = mb_strtolower($location['label']);
            if (isset($labels[$labelKey])) {
                return [];
            }
            $labels[$labelKey] = true;
            $locations[] = $location;
        }

        return $locations;
    }

    /** @param array{latitude: float, longitude: float} $coordinates */
    private function withinNetherlandsBounds(array $coordinates): bool
    {
        return $coordinates['latitude'] >= 50.7
            && $coordinates['latitude'] <= 53.7
            && $coordinates['longitude'] >= 3.2
            && $coordinates['longitude'] <= 7.3;
    }
}
