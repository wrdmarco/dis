<?php

namespace App\Services;

use App\Models\SystemSetting;

final class SoftwareDownloadService
{
    private const CHANNELS = [
        'operator_android' => ['store_key' => 'software.download.operator_android.app_store_url', 'source_key' => 'software.download.operator_android.source'],
        'admin_android' => ['store_key' => 'software.download.admin_android.app_store_url', 'source_key' => 'software.download.admin_android.source'],
        'operator_ios' => ['store_key' => 'software.download.operator_ios.app_store_url', 'source_key' => 'software.download.operator_ios.source'],
    ];

    /** @return array<string, array{source: string, app_store_url: string}> */
    public function channels(): array
    {
        $channels = [];
        foreach (self::CHANNELS as $key => $settings) {
            $source = SystemSetting::string($settings['source_key'], 'direct') ?? 'direct';
            $channels[$key] = [
                'source' => in_array($source, ['direct', 'app_store'], true) ? $source : 'direct',
                'app_store_url' => SystemSetting::string($settings['store_key'], '') ?? '',
            ];
        }

        return $channels;
    }
}
