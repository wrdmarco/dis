<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DeveloperAccessService;
use App\Services\SystemUpdateStatusService;
use App\Support\ApiDateTime;
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
            ? ApiDateTime::dateTime(Carbon::parse((string) $data['expires_at']))
            : ApiDateTime::dateTime(now()->addDays(30));

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
                    'generated_at' => ApiDateTime::now(),
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
                    'disabled_at' => ApiDateTime::now(),
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

        $scriptSucceeded = $this->runMaintenanceScript($enabled);
        if (! $scriptSucceeded) {
            if ($enabled) {
                $this->runArtisanMaintenanceCommand('down', ['--render' => 'errors::503']);
                $this->tryWriteFrontendMaintenanceLock($maintenanceDirectory, $pagePath, $lockPath);
            } else {
                $this->runArtisanMaintenanceCommand('up');
                $this->tryDeleteFrontendMaintenanceLock($lockPath);
            }
        }

        $this->auditService->record('system.maintenance_'.($enabled ? 'enabled' : 'disabled').'_developer_api', SystemSetting::class, null, [], null, $request);

        return ApiResponse::success([
            'enabled' => $enabled,
            'frontend_lock' => is_file($lockPath),
            'script_succeeded' => $scriptSucceeded,
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

    public function developerResetLoginLock(Request $request): JsonResponse
    {
        $this->developerAccess->authorize($request, DeveloperAccessService::SCOPE_USER_UNLOCK);

        $data = $request->validate([
            'email' => ['required_without:user_id', 'email:rfc', 'max:255'],
            'user_id' => ['required_without:email', 'ulid'],
        ]);

        $user = User::query()
            ->when(isset($data['user_id']), fn ($query) => $query->whereKey($data['user_id']))
            ->when(isset($data['email']), fn ($query) => $query->where('email', $data['email']))
            ->first();

        if ($user === null) {
            return ApiResponse::error('user_not_found', 'Gebruiker niet gevonden.', 404);
        }

        $before = [
            'failed_login_attempts' => (int) $user->failed_login_attempts,
            'login_locked_until' => ApiDateTime::dateTime($user->login_locked_until),
        ];
        $user->forceFill([
            'failed_login_attempts' => 0,
            'login_locked_until' => null,
        ])->save();

        $this->auditService->record('developer.user_login_lock_reset', $user, null, [
            'before' => $before,
        ], null, $request);

        return ApiResponse::success([
            'id' => $user->id,
            'email' => $user->email,
            'failed_login_attempts' => 0,
            'login_locked_until' => null,
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

    private function runMaintenanceScript(bool $enabled): bool
    {
        $root = realpath(base_path('../..')) ?: base_path('../..');
        $script = $root.'/scripts/maintenance.sh';
        if (! is_file($script)) {
            return false;
        }

        $bash = is_file('/usr/bin/bash') ? '/usr/bin/bash' : '/bin/bash';
        $result = Process::run(['sudo', '-n', $bash, $script, $enabled ? 'enable' : 'disable']);

        return $result->successful();
    }

    private function tryWriteFrontendMaintenanceLock(string $directory, string $pagePath, string $lockPath): bool
    {
        try {
            File::ensureDirectoryExists($directory);
            if (! is_file($pagePath)) {
                File::put($pagePath, $this->maintenancePageHtml());
            }
            File::put($lockPath, ApiDateTime::now());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function tryDeleteFrontendMaintenanceLock(string $lockPath): bool
    {
        try {
            if (is_file($lockPath)) {
                File::delete($lockPath);
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
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
    :root { color-scheme: dark; --bg: #070d16; --blue: #80c7ff; --green: #7dd3a7; --text: #f8fbff; --muted: #aebdd0; }
    * { box-sizing: border-box; }
    html, body { min-height: 100%; margin: 0; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    body {
      min-height: 100vh;
      overflow: hidden;
      display: grid;
      place-items: center;
      padding: 24px;
      background:
        radial-gradient(circle at 18% 18%, rgba(128, 199, 255, .16), transparent 30%),
        radial-gradient(circle at 84% 74%, rgba(125, 211, 167, .1), transparent 32%),
        linear-gradient(145deg, #060a10 0%, var(--bg) 46%, #0d1724 100%);
      color: var(--text);
    }
    .sky { position: fixed; inset: 0; pointer-events: none; overflow: hidden; }
    .drone-lane {
      position: absolute;
      left: -260px;
      top: 18%;
      width: 240px;
      height: 110px;
      animation: fly 13s linear infinite;
      opacity: .92;
      filter: drop-shadow(0 18px 28px rgba(0, 0, 0, .48));
    }
    .drone-lane:nth-child(2) { top: 42%; animation-duration: 17s; animation-delay: -7s; transform: scale(.78); opacity: .7; }
    .drone-lane:nth-child(3) { top: 70%; animation-duration: 19s; animation-delay: -12s; transform: scale(.92); opacity: .74; }
    .rotor-disc { transform-origin: center; animation: rotor .3s linear infinite; opacity: .76; }
    main {
      position: relative;
      z-index: 1;
      width: min(760px, calc(100vw - 40px));
      border: 1px solid rgba(128, 199, 255, .24);
      border-radius: 8px;
      padding: clamp(24px, 5vw, 44px);
      background: linear-gradient(180deg, rgba(17, 29, 43, .94), rgba(9, 13, 18, .96));
      box-shadow: 0 28px 96px rgba(0, 0, 0, .52), inset 0 1px 0 rgba(255, 255, 255, .04);
      overflow: hidden;
    }
    main::before { content: ""; position: absolute; inset: 0 0 auto; height: 3px; background: linear-gradient(90deg, var(--blue), var(--green), transparent); }
    span { display: inline-flex; align-items: center; gap: 10px; color: var(--blue); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    span::before { content: ""; width: 9px; height: 9px; border-radius: 999px; background: var(--green); box-shadow: 0 0 0 6px rgba(125, 211, 167, .12); animation: pulse 1.6s ease-in-out infinite; }
    h1 { max-width: 12ch; margin: 22px 0 12px; font-size: clamp(38px, 7vw, 72px); line-height: .94; }
    p { max-width: 58ch; margin: 0; color: var(--muted); font-size: clamp(16px, 2vw, 18px); line-height: 1.55; }
    @keyframes fly { from { translate: -12vw 0; } to { translate: calc(100vw + 300px) 0; } }
    @keyframes rotor { to { rotate: 360deg; } }
    @keyframes pulse { 0%, 100% { transform: scale(1); opacity: .95; } 50% { transform: scale(1.28); opacity: .62; } }
    @media (max-width: 680px) { body { min-height: 100svh; overflow-y: auto; place-items: center; padding: 18px; } main { width: min(100%, 420px); padding: 22px 20px 24px; } span { font-size: 11px; gap: 8px; } h1 { max-width: 100%; margin: 18px 0 12px; font-size: clamp(30px, 10vw, 42px); line-height: 1.02; } p { font-size: 15px; line-height: 1.5; } .drone-lane { width: 176px; height: 82px; left: -190px; top: 12%; opacity: .46; filter: drop-shadow(0 12px 18px rgba(0, 0, 0, .42)); } .drone-lane:nth-child(2) { top: 76%; opacity: .28; } .drone-lane:nth-child(3) { display: none; } }
    @media (prefers-reduced-motion: reduce) { .drone-lane, .rotor-disc, span::before { animation: none; } .drone-lane { translate: 18vw 0; } .drone-lane:nth-child(2), .drone-lane:nth-child(3) { display: none; } }
  </style>
</head>
<body>
  <div class="sky" aria-hidden="true">
    <svg class="drone-lane" viewBox="0 0 240 110"><defs><linearGradient id="body-a" x1="64" x2="174" y1="35" y2="76" gradientUnits="userSpaceOnUse"><stop stop-color="#e8f4ff"/><stop offset=".42" stop-color="#9bb3c9"/><stop offset="1" stop-color="#26384a"/></linearGradient><radialGradient id="rotor-a" cx="50%" cy="50%" r="50%"><stop stop-color="#f4fbff" stop-opacity=".48"/><stop offset=".58" stop-color="#a9dfff" stop-opacity=".16"/><stop offset="1" stop-color="#a9dfff" stop-opacity="0"/></radialGradient></defs><path d="M118 61 L64 108 H176 L124 61Z" fill="#80c7ff" opacity=".12"/><g class="rotor-disc"><ellipse cx="43" cy="24" rx="37" ry="9" fill="url(#rotor-a)"/><ellipse cx="197" cy="24" rx="37" ry="9" fill="url(#rotor-a)"/><ellipse cx="43" cy="80" rx="37" ry="9" fill="url(#rotor-a)"/><ellipse cx="197" cy="80" rx="37" ry="9" fill="url(#rotor-a)"/></g><g fill="none" stroke="#8fb0c9" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><path d="M93 50 L52 27"/><path d="M147 50 L188 27"/><path d="M92 61 L52 78"/><path d="M148 61 L188 78"/><path d="M88 86 C103 96 137 96 152 86"/></g><g fill="#162131" stroke="#d6edf8" stroke-width="2.5"><circle cx="43" cy="24" r="10"/><circle cx="197" cy="24" r="10"/><circle cx="43" cy="80" r="10"/><circle cx="197" cy="80" r="10"/></g><path d="M88 48 C92 33 106 26 120 26 C134 26 148 33 152 48 L158 65 C148 75 133 80 120 80 C107 80 92 75 82 65Z" fill="url(#body-a)" stroke="#e8f4ff" stroke-width="2.5"/><path d="M98 48 H142" stroke="#334a5f" stroke-width="3" stroke-linecap="round" opacity=".7"/><rect x="107" y="70" width="26" height="17" rx="7" fill="#111a26" stroke="#d6edf8" stroke-width="2"/><circle cx="120" cy="79" r="5" fill="#0c1119" stroke="#80c7ff" stroke-width="2"/><circle cx="89" cy="60" r="3" fill="#ef4444"/><circle cx="151" cy="60" r="3" fill="#7dd3a7"/><g fill="none" stroke="#eef8ff" stroke-width="2" stroke-linecap="round" opacity=".82"><path d="M15 24 H71"/><path d="M169 24 H225"/><path d="M15 80 H71"/><path d="M169 80 H225"/></g></svg>
    <svg class="drone-lane" viewBox="0 0 240 110"><defs><linearGradient id="body-b" x1="64" x2="174" y1="35" y2="76" gradientUnits="userSpaceOnUse"><stop stop-color="#e8f4ff"/><stop offset=".42" stop-color="#9bb3c9"/><stop offset="1" stop-color="#26384a"/></linearGradient><radialGradient id="rotor-b" cx="50%" cy="50%" r="50%"><stop stop-color="#f4fbff" stop-opacity=".48"/><stop offset=".58" stop-color="#a9dfff" stop-opacity=".16"/><stop offset="1" stop-color="#a9dfff" stop-opacity="0"/></radialGradient></defs><path d="M118 61 L64 108 H176 L124 61Z" fill="#80c7ff" opacity=".12"/><g class="rotor-disc"><ellipse cx="43" cy="24" rx="37" ry="9" fill="url(#rotor-b)"/><ellipse cx="197" cy="24" rx="37" ry="9" fill="url(#rotor-b)"/><ellipse cx="43" cy="80" rx="37" ry="9" fill="url(#rotor-b)"/><ellipse cx="197" cy="80" rx="37" ry="9" fill="url(#rotor-b)"/></g><g fill="none" stroke="#8fb0c9" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><path d="M93 50 L52 27"/><path d="M147 50 L188 27"/><path d="M92 61 L52 78"/><path d="M148 61 L188 78"/><path d="M88 86 C103 96 137 96 152 86"/></g><g fill="#162131" stroke="#d6edf8" stroke-width="2.5"><circle cx="43" cy="24" r="10"/><circle cx="197" cy="24" r="10"/><circle cx="43" cy="80" r="10"/><circle cx="197" cy="80" r="10"/></g><path d="M88 48 C92 33 106 26 120 26 C134 26 148 33 152 48 L158 65 C148 75 133 80 120 80 C107 80 92 75 82 65Z" fill="url(#body-b)" stroke="#e8f4ff" stroke-width="2.5"/><path d="M98 48 H142" stroke="#334a5f" stroke-width="3" stroke-linecap="round" opacity=".7"/><rect x="107" y="70" width="26" height="17" rx="7" fill="#111a26" stroke="#d6edf8" stroke-width="2"/><circle cx="120" cy="79" r="5" fill="#0c1119" stroke="#80c7ff" stroke-width="2"/><circle cx="89" cy="60" r="3" fill="#ef4444"/><circle cx="151" cy="60" r="3" fill="#7dd3a7"/><g fill="none" stroke="#eef8ff" stroke-width="2" stroke-linecap="round" opacity=".82"><path d="M15 24 H71"/><path d="M169 24 H225"/><path d="M15 80 H71"/><path d="M169 80 H225"/></g></svg>
    <svg class="drone-lane" viewBox="0 0 240 110"><defs><linearGradient id="body-c" x1="64" x2="174" y1="35" y2="76" gradientUnits="userSpaceOnUse"><stop stop-color="#e8f4ff"/><stop offset=".42" stop-color="#9bb3c9"/><stop offset="1" stop-color="#26384a"/></linearGradient><radialGradient id="rotor-c" cx="50%" cy="50%" r="50%"><stop stop-color="#f4fbff" stop-opacity=".48"/><stop offset=".58" stop-color="#a9dfff" stop-opacity=".16"/><stop offset="1" stop-color="#a9dfff" stop-opacity="0"/></radialGradient></defs><path d="M118 61 L64 108 H176 L124 61Z" fill="#80c7ff" opacity=".12"/><g class="rotor-disc"><ellipse cx="43" cy="24" rx="37" ry="9" fill="url(#rotor-c)"/><ellipse cx="197" cy="24" rx="37" ry="9" fill="url(#rotor-c)"/><ellipse cx="43" cy="80" rx="37" ry="9" fill="url(#rotor-c)"/><ellipse cx="197" cy="80" rx="37" ry="9" fill="url(#rotor-c)"/></g><g fill="none" stroke="#8fb0c9" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><path d="M93 50 L52 27"/><path d="M147 50 L188 27"/><path d="M92 61 L52 78"/><path d="M148 61 L188 78"/><path d="M88 86 C103 96 137 96 152 86"/></g><g fill="#162131" stroke="#d6edf8" stroke-width="2.5"><circle cx="43" cy="24" r="10"/><circle cx="197" cy="24" r="10"/><circle cx="43" cy="80" r="10"/><circle cx="197" cy="80" r="10"/></g><path d="M88 48 C92 33 106 26 120 26 C134 26 148 33 152 48 L158 65 C148 75 133 80 120 80 C107 80 92 75 82 65Z" fill="url(#body-c)" stroke="#e8f4ff" stroke-width="2.5"/><path d="M98 48 H142" stroke="#334a5f" stroke-width="3" stroke-linecap="round" opacity=".7"/><rect x="107" y="70" width="26" height="17" rx="7" fill="#111a26" stroke="#d6edf8" stroke-width="2"/><circle cx="120" cy="79" r="5" fill="#0c1119" stroke="#80c7ff" stroke-width="2"/><circle cx="89" cy="60" r="3" fill="#ef4444"/><circle cx="151" cy="60" r="3" fill="#7dd3a7"/><g fill="none" stroke="#eef8ff" stroke-width="2" stroke-linecap="round" opacity=".82"><path d="M15 24 H71"/><path d="M169 24 H225"/><path d="M15 80 H71"/><path d="M169 80 H225"/></g></svg>
  </div>
  <main>
    <span>Onderhoud actief</span>
    <h1>Drone Inzet Systeem wordt bijgewerkt</h1>
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
