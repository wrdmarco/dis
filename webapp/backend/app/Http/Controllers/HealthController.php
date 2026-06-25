<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

final class HealthController extends Controller
{
    public function public(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function admin(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => Queue::getDefaultDriver(),
            'websocket' => [
                'driver' => config('broadcasting.default'),
                'host' => config('reverb.servers.reverb.host'),
                'port' => config('reverb.servers.reverb.port'),
            ],
            'fcm' => [
                'project_configured' => filled(SystemSetting::string('firebase.project_id', config('dis.push.fcm_project_id'))),
                'service_account_configured' => $this->firebaseServiceAccountConfigured(),
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        return ApiResponse::success($checks);
    }

    public function queues(): JsonResponse
    {
        return ApiResponse::success(['driver' => Queue::getDefaultDriver()]);
    }

    public function websocket(): JsonResponse
    {
        return ApiResponse::success([
            'driver' => config('broadcasting.default'),
            'host' => config('reverb.servers.reverb.host'),
            'port' => config('reverb.servers.reverb.port'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        DB::connection()->getPdo();

        return [
            'status' => 'ok',
            'connection' => DB::getDefaultConnection(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCache(): array
    {
        Cache::put('healthcheck', 'ok', 30);

        return [
            'status' => Cache::get('healthcheck') === 'ok' ? 'ok' : 'failed',
            'store' => config('cache.default'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkStorage(): array
    {
        $path = 'healthcheck/'.now()->format('YmdHis').'.txt';
        Storage::disk('local')->put($path, 'ok');
        $ok = Storage::disk('local')->get($path) === 'ok';
        Storage::disk('local')->delete($path);

        return ['status' => $ok ? 'ok' : 'failed'];
    }

    private function firebaseServiceAccountConfigured(): bool
    {
        $credentials = SystemSetting::value('firebase.service_account', []);

        return is_array($credentials)
            && filled($credentials['client_email'] ?? null)
            && filled($credentials['private_key'] ?? null);
    }
}
