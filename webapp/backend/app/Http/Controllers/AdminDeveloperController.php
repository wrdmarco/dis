<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Jobs\RunSystemUpdate;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\SystemUpdateStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

final class AdminDeveloperController extends Controller
{
    private const ACCESS_KEY = 'developer.android_upload';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly SystemUpdateStatusService $updateStatus,
    ) {}

    public function developerAccess(): JsonResponse
    {
        return ApiResponse::success($this->developerAccessState());
    }

    public function generateDeveloperKey(Request $request): JsonResponse
    {
        $plainTextKey = 'dis_dev_'.bin2hex(random_bytes(32));
        SystemSetting::query()->updateOrCreate(
            ['key' => self::ACCESS_KEY],
            [
                'value' => [
                    'enabled' => true,
                    'key_hash' => hash('sha256', $plainTextKey),
                    'generated_at' => now()->toIso8601String(),
                    'disabled_at' => null,
                ],
                'is_sensitive' => true,
                'updated_by' => $request->user()?->id,
            ],
        );

        $this->auditService->record('developer.android_upload_key_generated', SystemSetting::class, $request->user(), [], null, $request);

        return ApiResponse::success($this->developerAccessState() + ['api_key' => $plainTextKey]);
    }

    public function disableDeveloperKey(Request $request): JsonResponse
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => self::ACCESS_KEY],
            [
                'value' => [
                    'enabled' => false,
                    'key_hash' => null,
                    'generated_at' => null,
                    'disabled_at' => now()->toIso8601String(),
                ],
                'is_sensitive' => true,
                'updated_by' => $request->user()?->id,
            ],
        );

        $this->auditService->record('developer.android_upload_key_disabled', SystemSetting::class, $request->user(), [], null, $request);

        return ApiResponse::success($this->developerAccessState());
    }

    public function version(): JsonResponse
    {
        return ApiResponse::success([
            'app_version' => $this->readVersionFile(),
            'git' => $this->gitState(),
            'updater' => $this->updateStatus->current(),
        ]);
    }

    public function runUpdate(Request $request): JsonResponse
    {
        $currentStatus = $this->updateStatus->current();
        if (($currentStatus['state'] ?? null) === 'running') {
            return ApiResponse::error('update_already_running', 'Er draait al een update.', 409);
        }

        $this->updateStatus->start('Update gestart vanuit admin.');
        RunSystemUpdate::dispatch()->onQueue('default');
        $this->auditService->record('system.update_started', SystemSetting::class, $request->user(), [], null, $request);

        return ApiResponse::success($this->updateStatus->current(), 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function developerAccessState(): array
    {
        $setting = SystemSetting::query()->find(self::ACCESS_KEY);
        $value = is_array($setting?->value) ? $setting->value : [];

        return [
            'enabled' => (bool) ($value['enabled'] ?? false),
            'configured' => filled($value['key_hash'] ?? null),
            'generated_at' => $value['generated_at'] ?? null,
            'disabled_at' => $value['disabled_at'] ?? null,
        ];
    }

    private function readVersionFile(): string
    {
        $path = base_path('../../VERSION');

        return is_file($path) ? trim((string) file_get_contents($path)) : 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function gitState(): array
    {
        $root = base_path('../..');
        $current = $this->runGit($root, ['rev-parse', 'HEAD']);
        $branch = $this->runGit($root, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $upstream = $this->runGit($root, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);

        $latest = null;
        $behind = null;
        if ($upstream !== null) {
            $this->runGit($root, ['fetch', '--prune']);
            $latest = $this->runGit($root, ['rev-parse', $upstream]);
            $count = $this->runGit($root, ['rev-list', '--left-right', '--count', 'HEAD...'.$upstream]);
            if ($count !== null) {
                $parts = preg_split('/\s+/', $count);
                $behind = isset($parts[1]) ? (int) $parts[1] : null;
            }
        }

        return [
            'current_commit' => $current,
            'branch' => $branch,
            'upstream' => $upstream,
            'latest_commit' => $latest,
            'behind' => $behind,
            'update_available' => $behind !== null && $behind > 0,
        ];
    }

    /**
     * @param list<string> $arguments
     */
    private function runGit(string $root, array $arguments): ?string
    {
        $result = Process::path($root)->run(['git', ...$arguments]);
        if (! $result->successful()) {
            return null;
        }

        $output = trim($result->output());

        return $output === '' ? null : $output;
    }
}
