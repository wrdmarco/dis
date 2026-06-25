<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $firebase = $this->firebaseConfig();
        $apiBaseUrl = SystemSetting::query()->find('mobile.api_base_url')?->value;

        return ApiResponse::success([
            'tenant_name' => SystemSetting::query()->find('mobile.tenant_name')?->value ?? config('app.name', 'D.I.S'),
            'api_base_url' => is_string($apiBaseUrl) && $apiBaseUrl !== '' ? $apiBaseUrl : $request->getSchemeAndHttpHost().'/api',
            'firebase' => $firebase,
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function firebaseConfig(): ?array
    {
        $config = SystemSetting::query()->find('mobile.firebase_config')?->value;

        if (! is_array($config)) {
            return null;
        }

        $required = ['application_id', 'api_key', 'project_id', 'messaging_sender_id'];
        foreach ($required as $key) {
            if (! isset($config[$key]) || ! is_string($config[$key]) || trim($config[$key]) === '') {
                return null;
            }
        }

        return [
            'application_id' => $config['application_id'],
            'api_key' => $config['api_key'],
            'project_id' => $config['project_id'],
            'messaging_sender_id' => $config['messaging_sender_id'],
            'storage_bucket' => isset($config['storage_bucket']) && is_string($config['storage_bucket']) ? $config['storage_bucket'] : '',
        ];
    }
}
