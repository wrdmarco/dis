<?php

namespace App\Services;

final class KnmiPrecipitationConfiguration
{
    private const API_BASE_URL = 'https://api.dataplatform.knmi.nl/open-data/v1';

    private const DOWNLOAD_HOST = 'knmi-kdp-datasets-eu-west-1.s3.eu-west-1.amazonaws.com';

    private const RADAR_DATASET = 'radar_forecast';

    private const RADAR_VERSION = '2.0';

    private const PROBABILITY_DATASET = 'seamless_precipitation_ensemble_forecast_probabilities';

    private const PROBABILITY_VERSION = '1.0';

    private const RADAR_HARD_MAXIMUM_BYTES = 16_777_216;

    private const PROBABILITY_HARD_MAXIMUM_BYTES = 134_217_728;

    public function __construct(private readonly KnmiOpenDataConfiguration $openData) {}

    public function apiKey(): ?string
    {
        return $this->openData->apiKey();
    }

    public function apiBaseUrl(): string
    {
        return $this->fixedString('api_base_url', self::API_BASE_URL);
    }

    public function downloadHost(): string
    {
        return $this->fixedString('download_host', self::DOWNLOAD_HOST);
    }

    public function radarDataset(): string
    {
        return $this->fixedString('radar_dataset', self::RADAR_DATASET);
    }

    public function radarVersion(): string
    {
        return $this->fixedString('radar_version', self::RADAR_VERSION);
    }

    public function probabilityDataset(): string
    {
        return $this->fixedString('probability_dataset', self::PROBABILITY_DATASET);
    }

    public function probabilityVersion(): string
    {
        return $this->fixedString('probability_version', self::PROBABILITY_VERSION);
    }

    public function storageRoot(): string
    {
        $root = trim((string) config('dis.knmi_precipitation.storage_root'));
        if ($root === '' || str_contains($root, "\0")) {
            throw new \RuntimeException('The KNMI precipitation storage root is invalid.');
        }

        $normalized = str_replace('\\', '/', $root);
        $segments = array_values(array_filter(
            explode('/', trim($normalized, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));
        $isWindowsDrive = preg_match('/\A[A-Za-z]:\//D', $normalized) === 1;
        $isUnc = str_starts_with($normalized, '//');
        $isAbsolute = str_starts_with($normalized, '/') || $isWindowsDrive;
        $minimumSegments = $isUnc ? 3 : ($isWindowsDrive ? 3 : 2);

        if (! $isAbsolute
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || count($segments) < $minimumSegments) {
            throw new \RuntimeException('The KNMI precipitation storage root must be a dedicated absolute directory.');
        }

        return rtrim($root, '/\\');
    }

    public function minimumBytes(string $dataset): int
    {
        return match ($dataset) {
            self::RADAR_DATASET => $this->positiveInt('radar_minimum_bytes', 102_400),
            self::PROBABILITY_DATASET => $this->positiveInt('probability_minimum_bytes', 5_242_880),
            default => throw new \InvalidArgumentException('Unsupported KNMI precipitation dataset.'),
        };
    }

    public function maximumBytes(string $dataset): int
    {
        [$configured, $hardMaximum] = match ($dataset) {
            self::RADAR_DATASET => [
                $this->positiveInt('radar_maximum_bytes', self::RADAR_HARD_MAXIMUM_BYTES),
                self::RADAR_HARD_MAXIMUM_BYTES,
            ],
            self::PROBABILITY_DATASET => [
                $this->positiveInt('probability_maximum_bytes', self::PROBABILITY_HARD_MAXIMUM_BYTES),
                self::PROBABILITY_HARD_MAXIMUM_BYTES,
            ],
            default => throw new \InvalidArgumentException('Unsupported KNMI precipitation dataset.'),
        };
        $minimum = min($this->minimumBytes($dataset), $hardMaximum);

        return max($minimum, min($configured, $hardMaximum));
    }

    public function connectTimeoutSeconds(): int
    {
        return min(60, $this->positiveInt('connect_timeout_seconds', 10));
    }

    public function downloadTimeoutSeconds(): int
    {
        return min(540, max(30, $this->positiveInt('download_timeout_seconds', 300)));
    }

    public function queryTimeoutSeconds(): int
    {
        return min(30, max(2, $this->positiveInt('query_timeout_seconds', 10)));
    }

    public function maximumReferenceAgeSeconds(): int
    {
        return min(3600, max(1800, $this->positiveInt('maximum_reference_age_seconds', 1800)));
    }

    public function integrityCacheSeconds(): int
    {
        return min(3600, max(30, $this->positiveInt('integrity_cache_seconds', 300)));
    }

    public function pointCacheSeconds(): int
    {
        return min(21_600, max(300, $this->positiveInt('point_cache_seconds', 3600)));
    }

    public function retainReleases(): int
    {
        return min(2, max(1, $this->positiveInt('retain_releases', 2)));
    }

    private function fixedString(string $key, string $expected): string
    {
        $configured = trim((string) config('dis.knmi_precipitation.'.$key));
        if (! hash_equals($expected, $configured)) {
            throw new \RuntimeException("The fixed KNMI precipitation {$key} is not configured safely.");
        }

        return $expected;
    }

    private function positiveInt(string $key, int $fallback): int
    {
        $value = config('dis.knmi_precipitation.'.$key, $fallback);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}
