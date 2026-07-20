<?php

namespace App\Services;

use App\Models\SystemSetting;

final class KnmiEdrConfiguration
{
    public const API_KEY_SETTING = 'weather.knmi_edr_api_key';

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
}
