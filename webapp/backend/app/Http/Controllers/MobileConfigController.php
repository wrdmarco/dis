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
            'api_base_url' => $this->normalizeApiBaseUrl(
                is_string($apiBaseUrl) && $apiBaseUrl !== '' ? $apiBaseUrl : $request->getSchemeAndHttpHost(),
            ),
            'firebase' => $firebase,
        ]);
    }

    private function normalizeApiBaseUrl(string $url): string
    {
        $normalized = rtrim($url, '/');
        $parts = parse_url($normalized);
        $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '';
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        $origin = $this->urlOrigin($normalized);

        if (in_array('api', $segments, true)) {
            $apiIndex = array_search('api', $segments, true);
            $apiPath = implode('/', array_slice($segments, 0, $apiIndex + 1));

            return $origin.'/'.$apiPath;
        }

        return $normalized.'/api';
    }

    private function urlOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return rtrim($url, '/');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
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
