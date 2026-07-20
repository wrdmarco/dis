<?php

namespace App\Services;

use App\Models\SystemSetting;

final class KnmiOpenDataConfiguration
{
    public const API_KEY_SETTING = 'weather.knmi_open_data_api_key';

    private const LEGACY_API_KEY_SETTING = 'weather.knmi_edr_api_key';

    private const API_BASE_URL = 'https://api.dataplatform.knmi.nl/open-data/v1';

    private const DOWNLOAD_HOST = 'knmi-kdp-datasets-eu-west-1.s3.eu-west-1.amazonaws.com';

    public function apiKey(): ?string
    {
        foreach ([
            SystemSetting::string(self::API_KEY_SETTING),
            $this->configString('dis.knmi_forecast.api_key'),
            SystemSetting::string(self::LEGACY_API_KEY_SETTING),
            $this->configString('dis.wallboards.uav_forecast.knmi_edr_api_key'),
        ] as $candidate) {
            if (is_string($candidate) && $this->validApiKey($candidate)) {
                return trim($candidate);
            }
        }

        return null;
    }

    public function keySource(): ?string
    {
        $candidates = [
            'open_data_setting' => SystemSetting::string(self::API_KEY_SETTING),
            'open_data_environment' => $this->configString('dis.knmi_forecast.api_key'),
            'legacy_edr_setting' => SystemSetting::string(self::LEGACY_API_KEY_SETTING),
            'legacy_edr_environment' => $this->configString('dis.wallboards.uav_forecast.knmi_edr_api_key'),
        ];
        foreach ($candidates as $source => $candidate) {
            if (is_string($candidate) && $this->validApiKey($candidate)) {
                return $source;
            }
        }

        return null;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    public function validApiKey(string $value): bool
    {
        $value = trim($value);

        return preg_match('/\A[\x21-\x7E]{16,2000}\z/D', $value) === 1;
    }

    public function apiBaseUrl(): string
    {
        $configured = rtrim((string) config('dis.knmi_forecast.api_base_url'), '/');

        if (! hash_equals(self::API_BASE_URL, $configured)) {
            throw new \RuntimeException('The fixed KNMI Open Data API endpoint is not configured safely.');
        }

        return self::API_BASE_URL;
    }

    public function downloadHost(): string
    {
        $configured = strtolower(trim((string) config('dis.knmi_forecast.download_host')));

        if (! hash_equals(self::DOWNLOAD_HOST, $configured)) {
            throw new \RuntimeException('The fixed KNMI download host is not configured safely.');
        }

        return self::DOWNLOAD_HOST;
    }

    public function dataset(): string
    {
        $dataset = (string) config('dis.knmi_forecast.dataset');
        if ($dataset !== 'harmonie_arome_cy43_p1') {
            throw new \RuntimeException('The fixed KNMI dataset is not configured safely.');
        }

        return $dataset;
    }

    public function datasetVersion(): string
    {
        $version = (string) config('dis.knmi_forecast.dataset_version');
        if ($version !== '1.0') {
            throw new \RuntimeException('The fixed KNMI dataset version is not configured safely.');
        }

        return $version;
    }

    public function storageRoot(): string
    {
        $root = trim((string) config('dis.knmi_forecast.storage_root'));
        if ($root === '' || str_contains($root, "\0")) {
            throw new \RuntimeException('The KNMI storage root is invalid.');
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
            throw new \RuntimeException('The KNMI storage root must be a dedicated absolute directory, not a broad filesystem root.');
        }

        return rtrim($root, '/\\');
    }

    public function minimumArchiveBytes(): int
    {
        return max(1, (int) config('dis.knmi_forecast.minimum_archive_bytes', 104_857_600));
    }

    public function maximumArchiveBytes(): int
    {
        return min(1_181_116_006, max($this->minimumArchiveBytes(), (int) config('dis.knmi_forecast.maximum_archive_bytes', 1_181_116_006)));
    }

    public function connectTimeoutSeconds(): int
    {
        return max(1, min(60, (int) config('dis.knmi_forecast.connect_timeout_seconds', 10)));
    }

    public function downloadTimeoutSeconds(): int
    {
        return max(60, min(3000, (int) config('dis.knmi_forecast.download_timeout_seconds', 1800)));
    }

    public function retainReleases(): int
    {
        return max(1, min(5, (int) config('dis.knmi_forecast.retain_releases', 2)));
    }

    private function configString(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
