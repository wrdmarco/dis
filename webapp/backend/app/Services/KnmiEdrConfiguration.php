<?php

namespace App\Services;

use App\Models\SystemSetting;

final class KnmiEdrConfiguration
{
    public const API_KEY_SETTING = 'weather.knmi_edr_api_key';

    public const COLLECTION_ENDPOINT = 'https://api.dataplatform.knmi.nl/edr/v1/collections/10-minute-in-situ-meteorological-observations';

    public function apiKey(): ?string
    {
        $configuredFallback = config('dis.wallboards.uav_forecast.knmi_edr_api_key');
        $value = SystemSetting::string(
            self::API_KEY_SETTING,
            is_string($configuredFallback) ? $configuredFallback : null,
        );

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    public function keySource(): ?string
    {
        $stored = SystemSetting::string(self::API_KEY_SETTING);
        if (is_string($stored) && trim($stored) !== '') {
            return 'edr_setting';
        }

        $environment = config('dis.wallboards.uav_forecast.knmi_edr_api_key');

        return is_string($environment) && trim($environment) !== ''
            ? 'edr_environment'
            : null;
    }
}
