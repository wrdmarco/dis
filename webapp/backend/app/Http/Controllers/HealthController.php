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
        $services = $this->serviceChecks();

        $checks = [
            'status' => $this->overallStatus($services),
            'generated_at' => now()->toIso8601String(),
            'services' => $services,
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function serviceChecks(): array
    {
        return [
            'backend' => [
                'status' => 'ok',
                'uptime_seconds' => $this->serverUptimeSeconds(),
            ],
            'database' => $this->safeCheck(fn (): array => $this->checkDatabase()),
            'cache' => $this->safeCheck(fn (): array => $this->checkCache()),
            'storage' => $this->safeCheck(fn (): array => $this->checkStorage()),
            'queue' => [
                'status' => 'ok',
                'driver' => Queue::getDefaultDriver(),
            ],
            'websocket' => [
                'status' => filled(config('broadcasting.default')) ? 'ok' : 'unknown',
                'driver' => config('broadcasting.default'),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $services
     */
    private function overallStatus(array $services): string
    {
        return collect($services)->contains(fn (array $service): bool => ($service['status'] ?? null) === 'failed')
            ? 'degraded'
            : 'ok';
    }

    /**
     * @param callable(): array<string, mixed> $callback
     * @return array<string, mixed>
     */
    private function safeCheck(callable $callback): array
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            report($exception);

            return ['status' => 'failed'];
        }
    }

    private function serverUptimeSeconds(): ?int
    {
        $uptime = @file_get_contents('/proc/uptime');
        if (! is_string($uptime)) {
            return null;
        }

        $seconds = (float) (explode(' ', trim($uptime))[0] ?? 0);

        return $seconds > 0 ? (int) floor($seconds) : null;
    }
}
