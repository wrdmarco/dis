<?php

namespace App\Services;

use App\Contracts\OperationalRadarProvider;
use Carbon\CarbonImmutable;

final class OperationalWeatherRadarPageService
{
    public function __construct(
        private readonly WallboardForecastLocationService $locations,
        private readonly OperationalRadarProvider $radar,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function stateForOptions(array $options): array
    {
        $resolution = $this->locations->resolve($options);
        $centre = $this->centre(($resolution['complete'] ?? false) === true
            ? (array) ($resolution['locations'] ?? [])
            : []);

        return [
            'location' => [
                'mode' => (string) ($resolution['mode'] ?? WallboardForecastLocationService::MODE_NETHERLANDS),
                'label' => (string) ($resolution['label'] ?? WallboardForecastLocationService::NETHERLANDS_LABEL),
                'latitude' => $centre['latitude'],
                'longitude' => $centre['longitude'],
            ],
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'radar' => $this->radar->metadata(),
        ];
    }

    /**
     * @param  list<array{latitude?: mixed, longitude?: mixed}>  $locations
     * @return array{latitude: float|null, longitude: float|null}
     */
    private function centre(array $locations): array
    {
        $coordinates = array_values(array_filter(
            $locations,
            static fn (array $location): bool => is_numeric($location['latitude'] ?? null)
                && is_numeric($location['longitude'] ?? null),
        ));
        if ($coordinates === []) {
            return ['latitude' => null, 'longitude' => null];
        }

        return [
            'latitude' => array_sum(array_map(
                static fn (array $location): float => (float) $location['latitude'],
                $coordinates,
            )) / count($coordinates),
            'longitude' => array_sum(array_map(
                static fn (array $location): float => (float) $location['longitude'],
                $coordinates,
            )) / count($coordinates),
        ];
    }
}
