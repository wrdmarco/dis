<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\DeveloperAccessService;
use App\Services\SystemUpdateStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;
use Symfony\Component\Process\PhpExecutableFinder;

final class AdminDeveloperController extends Controller
{
    private const ACCESS_KEY = 'developer.android_upload';
    private const GIT_BRANCH = 'main';
    private const UPDATE_TIMEOUT_SECONDS = 3300;

    public function __construct(
        private readonly AuditService $auditService,
        private readonly DeveloperAccessService $developerAccess,
        private readonly SystemUpdateStatusService $updateStatus,
    ) {}

    public function developerAccess(): JsonResponse
    {
        return ApiResponse::success($this->developerAccessState());
    }

    public function generateDeveloperKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scopes' => ['nullable', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(DeveloperAccessService::SCOPES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'allowed_ips' => ['nullable', 'array', 'max:20'],
            'allowed_ips.*' => [
                'string',
                'max:64',
                fn (string $attribute, mixed $value, \Closure $fail) => $this->validateDeveloperIpPattern($value, $fail),
            ],
        ]);
        $scopes = array_values(array_unique($data['scopes'] ?? DeveloperAccessService::SCOPES));
        $allowedIps = $this->normalizedDeveloperAllowedIps($data['allowed_ips'] ?? []);
        $expiresAt = isset($data['expires_at'])
            ? Carbon::parse((string) $data['expires_at'])->toIso8601String()
            : now()->addDays(30)->toIso8601String();

        $plainTextKey = 'dis_dev_'.bin2hex(random_bytes(32));
        SystemSetting::query()->updateOrCreate(
            ['key' => self::ACCESS_KEY],
            [
                'value' => [
                    'enabled' => true,
                    'key_hash' => hash('sha256', $plainTextKey),
                    'scopes' => $scopes,
                    'expires_at' => $expiresAt,
                    'allowed_ips' => $allowedIps,
                    'generated_at' => now()->toIso8601String(),
                    'disabled_at' => null,
                ],
                'is_sensitive' => true,
                'updated_by' => $request->user()?->id,
            ],
        );

        $this->auditService->record('developer.api_key_generated', SystemSetting::class, $request->user(), [
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
            'allowed_ips_count' => count($allowedIps),
        ], null, $request);

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
                    'scopes' => [],
                    'expires_at' => null,
                    'allowed_ips' => [],
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
            'system' => [
                'reboot_required' => $this->rebootRequired(),
            ],
            'updater' => $this->updateStatus->current(),
        ]);
    }

    public function runUpdate(Request $request): JsonResponse
    {
        $currentStatus = $this->updateStatus->current();
        if (($currentStatus['state'] ?? null) === 'running') {
            return ApiResponse::error('update_already_running', 'Er draait al een update.', 409);
        }

        $updateSystem = $request->boolean('update_system');
        $this->updateStatus->start($updateSystem ? 'Systeem- en app-update gestart vanuit admin.' : 'App-update gestart vanuit admin.');
        if (! $this->startUpdateProcess($updateSystem)) {
            $this->updateStatus->append('Updateproces kon niet als achtergrondproces worden gestart.');
            $this->updateStatus->finish(1);

            return ApiResponse::error('update_start_failed', 'Updateproces kon niet worden gestart.', 500);
        }
        $this->auditService->record('system.update_started', SystemSetting::class, $request->user(), ['update_system' => $updateSystem], null, $request);

        return ApiResponse::success($this->updateStatus->current(), 202);
    }

    public function reboot(Request $request): JsonResponse
    {
        $this->auditService->record('system.reboot_requested', SystemSetting::class, $request->user(), [], null, $request);

        $command = is_file('/usr/bin/systemctl') ? '/usr/bin/systemctl' : '/bin/systemctl';
        $result = Process::run(['sudo', '-n', $command, 'reboot']);
        if (! $result->successful()) {
            return ApiResponse::error('server_reboot_failed', trim($result->errorOutput()) ?: 'Serverherstart kon niet worden gestart.', 500);
        }

        return ApiResponse::success(['reboot_started' => true], 202);
    }

    public function developerRunUpdate(Request $request): JsonResponse
    {
        $this->developerAccess->authorize($request, DeveloperAccessService::SCOPE_SYSTEM_UPDATE);

        $currentStatus = $this->updateStatus->current();
        if (($currentStatus['state'] ?? null) === 'running') {
            return ApiResponse::error('update_already_running', 'Er draait al een update.', 409);
        }

        $updateSystem = $request->boolean('update_system');
        $this->updateStatus->start($updateSystem ? 'Systeem- en app-update gestart via developer API.' : 'App-update gestart via developer API.');
        if (! $this->startUpdateProcess($updateSystem)) {
            $this->updateStatus->append('Updateproces kon niet als achtergrondproces worden gestart.');
            $this->updateStatus->finish(1);

            return ApiResponse::error('update_start_failed', 'Updateproces kon niet worden gestart.', 500);
        }
        $this->auditService->record('system.update_started_developer_api', SystemSetting::class, null, ['update_system' => $updateSystem], null, $request);

        return ApiResponse::success($this->updateStatus->current(), 202);
    }

    public function developerMaintenance(Request $request): JsonResponse
    {
        $this->developerAccess->authorize($request, DeveloperAccessService::SCOPE_SYSTEM_UPDATE);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['enabled'];
        $root = realpath(base_path('../..')) ?: base_path('../..');
        $maintenanceDirectory = $root.'/maintenance';
        $lockPath = $maintenanceDirectory.'/frontend.lock';
        $pagePath = $maintenanceDirectory.'/__dis_maintenance.html';

        if ($enabled) {
            File::ensureDirectoryExists($maintenanceDirectory);
            if (! is_file($pagePath)) {
                File::put($pagePath, $this->maintenancePageHtml());
            }
            File::put($lockPath, now()->toIso8601String());
            $this->runArtisanMaintenanceCommand('down', ['--render' => 'errors::503']);
        } else {
            $this->runArtisanMaintenanceCommand('up');
            if (is_file($lockPath)) {
                File::delete($lockPath);
            }
        }

        $this->auditService->record('system.maintenance_'.($enabled ? 'enabled' : 'disabled').'_developer_api', SystemSetting::class, null, [], null, $request);

        return ApiResponse::success([
            'enabled' => $enabled,
            'frontend_lock' => is_file($lockPath),
        ]);
    }

    public function developerLogs(Request $request): JsonResponse
    {
        $this->developerAccess->authorize($request, DeveloperAccessService::SCOPE_LOGS_READ);

        $logs = [];
        foreach ($this->logPaths() as $path) {
            $logs[] = [
                'name' => basename($path),
                'size_bytes' => filesize($path) ?: 0,
                'modified_at' => date(DATE_ATOM, filemtime($path) ?: time()),
            ];
        }

        usort($logs, fn (array $left, array $right): int => strcmp((string) $right['modified_at'], (string) $left['modified_at']));
        $this->auditService->record('developer.logs_listed', SystemSetting::class, null, ['log_count' => count($logs)], null, $request);

        return ApiResponse::success([
            'logs' => $logs,
            'updater' => $this->updateStatus->current(),
        ]);
    }

    public function developerLog(Request $request, string $filename): JsonResponse
    {
        $this->developerAccess->authorize($request, DeveloperAccessService::SCOPE_LOGS_READ);

        abort_unless(preg_match('/^[A-Za-z0-9._-]+\.log$/', $filename) === 1, 404);
        $path = $this->findLogPath($filename);
        abort_unless($path !== null, 404);

        $maxLines = min(max((int) $request->integer('lines', 200), 1), 1000);
        $content = $this->tailFile($path, 512 * 1024);
        $lines = array_slice(preg_split('/\R/', $content) ?: [], -$maxLines);
        $lines = array_map(fn (string $line): string => $this->redactLogLine($line), $lines);
        $this->auditService->record('developer.log_read', SystemSetting::class, null, [
            'filename' => basename($path),
            'lines' => count($lines),
        ], null, $request);

        return ApiResponse::success([
            'name' => basename($path),
            'size_bytes' => filesize($path) ?: 0,
            'modified_at' => date(DATE_ATOM, filemtime($path) ?: time()),
            'lines' => $lines,
        ]);
    }

    /**
     * @return list<string>
     */
    private function logPaths(): array
    {
        $directories = [
            storage_path('logs'),
            '/opt/dis-data/webapp/backend/storage/logs',
            '/opt/dis-data/storage/logs',
        ];
        $paths = [];

        foreach (array_values(array_unique($directories)) as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (glob($directory.'/*.log') ?: [] as $path) {
                if (! is_file($path)) {
                    continue;
                }

                $name = basename($path);
                $paths[$name] ??= $path;
            }
        }

        return array_values($paths);
    }

    private function findLogPath(string $filename): ?string
    {
        foreach ($this->logPaths() as $path) {
            if (basename($path) === $filename) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function developerAccessState(): array
    {
        $setting = SystemSetting::query()->find(self::ACCESS_KEY);
        $value = is_array($setting?->value) ? $setting->value : [];
        $allowedIps = is_array($value['allowed_ips'] ?? null) ? array_values(array_filter($value['allowed_ips'], 'is_string')) : [];

        return [
            'enabled' => (bool) ($value['enabled'] ?? false),
            'configured' => filled($value['key_hash'] ?? null),
            'scopes' => $this->developerAccess->configuredScopes($value),
            'available_scopes' => DeveloperAccessService::SCOPES,
            'expires_at' => $value['expires_at'] ?? null,
            'expired' => $this->developerAccess->isExpired($value['expires_at'] ?? null),
            'allowed_ips' => $allowedIps,
            'allowed_ips_count' => count($allowedIps),
            'legacy_unscoped' => filled($value['key_hash'] ?? null) && ! is_array($value['scopes'] ?? null),
            'generated_at' => $value['generated_at'] ?? null,
            'disabled_at' => $value['disabled_at'] ?? null,
        ];
    }

    private function validateDeveloperIpPattern(mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || ! $this->developerAccess->isAllowedIpPattern($value)) {
            $fail('Gebruik een geldig IP-adres of CIDR-blok.');
        }
    }

    /**
     * @param mixed $allowedIps
     * @return list<string>
     */
    private function normalizedDeveloperAllowedIps(mixed $allowedIps): array
    {
        if (! is_array($allowedIps)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $value): string => is_string($value) ? trim($value) : '', $allowedIps),
            fn (string $value): bool => $value !== '',
        )));
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
        $errors = [];
        $current = $this->runGit($root, ['rev-parse', 'HEAD']);
        $branch = $this->runGit($root, ['rev-parse', '--abbrev-ref', 'HEAD']);
        $upstream = $this->runGit($root, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{u}']);
        $fetchSuccessful = null;

        if ($upstream === null && $branch !== null && $branch !== 'HEAD') {
            $originBranch = 'origin/'.$branch;
            if ($this->runGit($root, ['rev-parse', '--verify', $originBranch]) !== null) {
                $upstream = $originBranch;
            }
        }

        if ($upstream === null && $this->runGit($root, ['rev-parse', '--verify', 'origin/'.self::GIT_BRANCH]) !== null) {
            $upstream = 'origin/'.self::GIT_BRANCH;
        }

        $latest = null;
        $behind = null;
        if ($upstream !== null) {
            $latest = $this->runGit($root, ['rev-parse', $upstream]);
            $count = $this->runGit($root, ['rev-list', '--left-right', '--count', 'HEAD...'.$upstream]);
            if ($count !== null) {
                $parts = preg_split('/\s+/', $count);
                $behind = isset($parts[1]) ? (int) $parts[1] : null;
            } else {
                $errors[] = 'Git achterstand kon niet worden berekend.';
            }
        }

        $remoteLatest = $this->remoteLatestCommit($root);
        if ($remoteLatest !== null) {
            $latest = $remoteLatest;
            $upstream ??= 'origin/'.self::GIT_BRANCH;
            if ($current !== null && $current !== $remoteLatest && ($behind === null || $behind === 0)) {
                $behind = 1;
            }
        }

        return [
            'current_commit' => $current,
            'branch' => $branch,
            'upstream' => $upstream,
            'latest_commit' => $latest,
            'behind' => $behind,
            'fetch_successful' => $fetchSuccessful,
            'checkable' => ($upstream !== null && $behind !== null) || ($current !== null && $remoteLatest !== null),
            'errors' => $errors,
            'update_available' => ($behind !== null && $behind > 0) || ($current !== null && $remoteLatest !== null && $current !== $remoteLatest),
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

    private function remoteLatestCommit(string $root): ?string
    {
        $output = $this->runGit($root, ['ls-remote', 'origin', 'refs/heads/'.self::GIT_BRANCH]);
        if ($output === null) {
            return null;
        }

        $parts = preg_split('/\s+/', $output);
        $commit = $parts[0] ?? null;

        return is_string($commit) && preg_match('/^[a-f0-9]{40}$/', $commit) === 1 ? $commit : null;
    }

    private function rebootRequired(): bool
    {
        return is_file('/var/run/reboot-required') || is_file('/run/reboot-required');
    }

    private function startUpdateProcess(bool $updateSystem): bool
    {
        $root = realpath(base_path('../..')) ?: base_path('../..');
        $php = (new PhpExecutableFinder())->find() ?: PHP_BINARY;
        if (! is_string($php) || $php === '') {
            $php = '/usr/bin/php';
        }

        $updateCommand = is_file('/usr/local/bin/update') ? '/usr/local/bin/update' : (realpath($root.'/update.sh') ?: $root.'/update.sh');
        $updateArguments = [
            'sudo',
            '-n',
            $updateCommand,
        ];
        if (! $updateSystem) {
            $updateArguments[] = '--skip-system';
        }

        $logPath = storage_path('logs/system-update-runner.log');
        $script = $this->updateRunnerScript($root, $php, $updateArguments, $logPath, $updateSystem);

        if ($this->startUpdateProcessWithSystemd($updateSystem)) {
            return true;
        }

        return $this->startUpdateProcessWithNohup($script);
    }

    /**
     * @param list<string> $updateArguments
     */
    private function updateRunnerScript(string $root, string $php, array $updateArguments, string $logPath, bool $updateSystem): string
    {
        $timeout = is_file('/usr/bin/timeout') ? '/usr/bin/timeout' : 'timeout';
        $updateLine = implode(' ', array_map('escapeshellarg', [$timeout, self::UPDATE_TIMEOUT_SECONDS.'s', ...$updateArguments]));
        $finishLine = escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' dis:finish-update "${exit_code}"';

        return implode("\n", [
            'exec >> '.escapeshellarg($logPath).' 2>&1',
            'cd '.escapeshellarg($root).' || exit 1',
            'echo '.escapeshellarg($updateSystem ? '[dis] Updatecommando gestart met systeemupdates.' : '[dis] Updatecommando gestart zonder systeemupdates.'),
            $updateLine,
            'exit_code=$?',
            'if [ "${exit_code}" -eq 124 ]; then echo '.escapeshellarg('[dis] Updateproces duurde te lang en is afgebroken.').'; fi',
            'echo "[dis] Updatecommando afgerond met exit code ${exit_code}."',
            'cd '.escapeshellarg(base_path()).' || true',
            $finishLine.' || true',
            'exit "${exit_code}"',
        ]);
    }

    private function startUpdateProcessWithSystemd(bool $updateSystem): bool
    {
        $systemdRun = is_file('/usr/bin/systemd-run') ? '/usr/bin/systemd-run' : (is_file('/bin/systemd-run') ? '/bin/systemd-run' : null);
        $runner = '/usr/local/bin/dis-update-runner';
        if ($systemdRun === null || ! is_file($runner)) {
            return false;
        }

        $unit = $updateSystem ? 'dis-update-system' : 'dis-update-app';
        $command = [
            'sudo',
            '-n',
            $systemdRun,
            '--unit='.$unit,
            '--collect',
            '--property=Type=simple',
            '--property=KillMode=process',
            $runner,
        ];
        if (! $updateSystem) {
            $command[] = '--skip-system';
        }
        $result = Process::run($command);
        if (! $result->successful()) {
            $message = trim($result->errorOutput()) ?: trim($result->output());
            if ($message !== '') {
                $this->updateStatus->append('systemd-run kon update niet starten; fallback wordt geprobeerd: '.$message);
            }

            return false;
        }

        $this->updateStatus->append('Update achtergrondproces gestart via systemd unit '.$unit.'.');
        $this->updateStatus->markSystemdUnitStarted($unit);

        return true;
    }

    private function startUpdateProcessWithNohup(string $script): bool
    {
        $command = 'nohup /bin/bash -lc '.escapeshellarg($script).' < /dev/null & echo $!';
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['/bin/bash', '-lc', $command], $descriptorSpec, $pipes, base_path());
        if (! is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        $pid = trim(stream_get_contents($pipes[1]) ?: '');
        $error = trim(stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $pid === '') {
            if ($error !== '') {
                $this->updateStatus->append($error);
            }

            return false;
        }

        $this->updateStatus->append('Update achtergrondproces gestart met PID '.$pid.'.');
        if (ctype_digit($pid)) {
            $this->updateStatus->markProcessStarted((int) $pid);
        }

        return true;
    }

    /**
     * @param array<string, string> $options
     */
    private function runArtisanMaintenanceCommand(string $command, array $options = []): void
    {
        $arguments = [base_path('artisan'), $command];
        foreach ($options as $key => $value) {
            $arguments[] = $key.'='.$value;
        }

        Process::run([(new PhpExecutableFinder())->find() ?: PHP_BINARY, ...$arguments]);
    }

    private function maintenancePageHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>D.I.S onderhoud</title>
  <style>
    html, body { height: 100%; margin: 0; font-family: Arial, sans-serif; background: #07111f; color: #f5f8fb; }
    body { display: grid; place-items: center; padding: 24px; box-sizing: border-box; }
    main { max-width: 720px; border: 1px solid #26384f; border-radius: 12px; padding: 28px; background: #101a28; }
    span { color: #67d7f5; font-size: 12px; font-weight: 800; text-transform: uppercase; }
    h1 { margin: 10px 0; font-size: clamp(28px, 6vw, 48px); }
    p { color: #b8c7d8; line-height: 1.6; }
  </style>
</head>
<body>
  <main>
    <span>Onderhoud actief</span>
    <h1>D.I.S is tijdelijk niet beschikbaar</h1>
    <p>De operationele omgeving staat tijdelijk in onderhoud. De app en webconsole komen automatisch terug zodra de controle is afgerond.</p>
  </main>
</body>
</html>
HTML;
    }

    private function tailFile(string $path, int $maxBytes): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            $size = filesize($path) ?: 0;
            if ($size > $maxBytes) {
                fseek($handle, -$maxBytes, SEEK_END);
                fgets($handle);
            }

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    private function redactLogLine(string $line): string
    {
        $patterns = [
            '/(Authorization:\s*Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
            '/(X-DIS-Developer-Key:\s*)\S+/i',
            '/((?:api[_-]?key|token|secret|password)[\'"\s:=]+)[^\'"\s,}]+/i',
        ];

        return preg_replace($patterns, '$1[redacted]', $line) ?? $line;
    }

}
