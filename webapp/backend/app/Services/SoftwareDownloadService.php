<?php

namespace App\Services;

use App\Models\SystemSetting;

final class SoftwareDownloadService
{
    private const CHANNELS = [
        'operator_android' => 'software.download.operator_android.app_store_url',
        'operator_ios' => 'software.download.operator_ios.app_store_url',
    ];

    /** @return array<string, array{app_store_url: string}> */
    public function channels(): array
    {
        $channels = [];
        foreach (self::CHANNELS as $key => $settingKey) {
            $channels[$key] = [
                'app_store_url' => SystemSetting::string($settingKey, '') ?? '',
            ];
        }

        return $channels;
    }
}
