<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Http\Request;

final class DeveloperAccessService
{
    public function authorize(Request $request): void
    {
        $providedKey = (string) $request->header('X-DIS-Developer-Key', '');
        $setting = SystemSetting::query()->find('developer.android_upload');
        $value = is_array($setting?->value) ? $setting->value : [];
        $expectedHash = $value['key_hash'] ?? null;

        if (($value['enabled'] ?? false) !== true || ! is_string($expectedHash) || $expectedHash === '') {
            abort(403, 'Developer API is disabled.');
        }

        if ($providedKey === '' || ! hash_equals($expectedHash, hash('sha256', $providedKey))) {
            abort(401, 'Invalid developer API key.');
        }
    }
}
