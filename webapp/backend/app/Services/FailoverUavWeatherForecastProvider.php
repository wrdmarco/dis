<?php

namespace App\Services;

use App\Contracts\UavWeatherForecastProvider;
use Throwable;

final class FailoverUavWeatherForecastProvider implements UavWeatherForecastProvider
{
    public function __construct(
        private readonly UavWeatherForecastProvider $primary,
        private readonly UavWeatherForecastProvider $fallback,
    ) {}

    /**
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    public function forResolution(array $resolution): array
    {
        try {
            $primary = $this->primary->forResolution($resolution);
        } catch (Throwable) {
            $primary = $this->unavailablePrimary();
        }

        if ($this->isFreshAndComplete($primary)) {
            return $primary;
        }

        try {
            $fallback = $this->fallback->forResolution($resolution);
        } catch (Throwable) {
            return $primary;
        }

        return $this->isFreshAndComplete($fallback) ? $fallback : $primary;
    }

    /** @param array<string, mixed> $reading */
    private function isFreshAndComplete(array $reading): bool
    {
        return ($reading['complete'] ?? false) === true
            && ($reading['stale'] ?? true) === false;
    }

    /** @return array<string, mixed> */
    private function unavailablePrimary(): array
    {
        return [
            'complete' => false,
            'stale' => false,
            'source' => null,
            'model_run_at' => null,
            'valid_at' => null,
            'measured_at' => null,
            'refreshed_at' => null,
            'sample_count' => 0,
            'expected_sample_count' => 0,
            'cloud_base_m' => null,
            'cloud_base_sample_count' => 0,
            'cloud_base_expected_sample_count' => 0,
            'cloud_base_complete' => false,
            'availability_note' => 'De primaire live weerservice gaf geen bruikbaar antwoord.',
        ];
    }
}
