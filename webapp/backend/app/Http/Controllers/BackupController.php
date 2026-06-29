<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;

final class BackupController extends Controller
{
    private const RESTORE_CONFIRMATION = 'HERSTEL BACKUP';
    private const DEFAULT_LOCAL_PATH = '/opt/dis/backup';
    private const PASSWORD_KEY = 'backup.samba.password';

    public function __construct(private readonly AuditService $auditService) {}

    public function index(): JsonResponse
    {
        $backups = [];

        foreach (['local', 'samba'] as $target) {
            $root = $this->backupRoot($target);
            if (! is_dir($root)) {
                continue;
            }

            foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $path) {
                $id = basename($path);
                if (! $this->validBackupId($id)) {
                    continue;
                }

                $backups[] = $this->backupSummary($id, $path, $target);
            }
        }

        usort($backups, fn (array $left, array $right): int => strcmp((string) $right['created_at'], (string) $left['created_at']));

        return ApiResponse::success([
            'root' => $this->backupRoot(),
            'roots' => [
                'local' => $this->backupRoot('local'),
                'samba' => $this->backupRoot('samba'),
            ],
            'settings' => $this->settingsState(),
            'confirmation_text' => self::RESTORE_CONFIRMATION,
            'backups' => $backups,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'in:local,samba'],
            'local_path' => ['nullable', 'string', 'in:/opt/dis/backup'],
            'samba_share' => ['nullable', 'string', 'max:255'],
            'samba_mount' => ['nullable', 'string', 'in:/mnt/dis-backup'],
            'samba_username' => ['nullable', 'string', 'max:255'],
            'samba_password' => ['nullable', 'string', 'max:2000'],
            'samba_domain' => ['nullable', 'string', 'max:255'],
            'samba_version' => ['nullable', 'string', 'max:20'],
        ]);

        if ($data['target'] === 'samba') {
            foreach (['samba_share', 'samba_mount', 'samba_username'] as $field) {
                if (trim((string) ($data[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([$field => ['Dit veld is verplicht voor Samba backups.']]);
                }
            }
        }

        $this->putSetting('backup.target', $data['target'], $request);
        $this->putSetting('backup.local_path', trim((string) ($data['local_path'] ?? '')) ?: self::DEFAULT_LOCAL_PATH, $request);
        $this->putSetting('backup.samba.share', trim((string) ($data['samba_share'] ?? '')), $request);
        $this->putSetting('backup.samba.mount', trim((string) ($data['samba_mount'] ?? '')) ?: '/mnt/dis-backup', $request);
        $this->putSetting('backup.samba.username', trim((string) ($data['samba_username'] ?? '')), $request);
        $this->putSetting('backup.samba.domain', trim((string) ($data['samba_domain'] ?? '')), $request);
        $this->putSetting('backup.samba.version', trim((string) ($data['samba_version'] ?? '')) ?: '3.1.1', $request);
        if (array_key_exists('samba_password', $data) && trim((string) $data['samba_password']) !== '') {
            $this->putSetting(self::PASSWORD_KEY, (string) $data['samba_password'], $request, true);
        }

        $this->writeRuntimeConfig();
        $this->auditService->record('backups.settings_updated', SystemSetting::class, $request->user(), [
            'target' => $data['target'],
            'samba_share' => $data['target'] === 'samba' ? ($data['samba_share'] ?? null) : null,
        ], null, $request);

        return $this->index();
    }

    public function create(Request $request): JsonResponse
    {
        $target = $this->requestTarget($request);
        $this->ensureTargetReady($target);
        $this->writeRuntimeConfig($target);
        $result = Process::timeout(900)->run(['sudo', '-n', 'bash', $this->scriptPath('backup.sh')]);
        $output = $this->cleanOutput($result->output().$result->errorOutput());

        $this->auditService->record('backups.created', SystemSetting::class, $request->user(), [
            'target' => $target,
            'successful' => $result->successful(),
        ], null, $request);

        if (! $result->successful()) {
            return ApiResponse::error('backup_failed', $output ?: 'Backup maken mislukt.', 500);
        }

        return ApiResponse::success([
            'output' => $output,
            'state' => 'succeeded',
            'backups' => $this->index()->getData(true)['data']['backups'] ?? [],
        ], 201);
    }

    public function verify(Request $request, string $backup): JsonResponse
    {
        $target = $this->requestTarget($request);
        $this->ensureTargetReady($target);
        $this->writeRuntimeConfig($target);
        $path = $this->backupPath($backup, $target);
        $result = Process::timeout(600)->run(['sudo', '-n', 'bash', $this->scriptPath('verify-backup.sh'), $path]);
        $output = $this->cleanOutput($result->output().$result->errorOutput());

        $this->auditService->record('backups.verified', SystemSetting::class, $request->user(), [
            'backup' => $backup,
            'target' => $target,
            'successful' => $result->successful(),
        ], null, $request);

        if (! $result->successful()) {
            return ApiResponse::error('backup_verify_failed', $output ?: 'Backup verificatie mislukt.', 422);
        }

        return ApiResponse::success([
            'backup' => $backup,
            'state' => 'verified',
            'output' => $output,
        ]);
    }

    public function restore(Request $request, string $backup): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string'],
            'target' => ['nullable', 'string', 'in:local,samba'],
        ]);
        if ($data['confirmation'] !== self::RESTORE_CONFIRMATION) {
            throw ValidationException::withMessages(['confirmation' => ['Bevestigingstekst klopt niet.']]);
        }

        $target = $this->requestTarget($request);
        $this->ensureTargetReady($target);
        $this->writeRuntimeConfig($target);
        $path = $this->backupPath($backup, $target);
        $result = Process::timeout(1200)->run(['sudo', '-n', 'bash', $this->scriptPath('restore.sh'), $path]);
        $output = $this->cleanOutput($result->output().$result->errorOutput());

        $this->auditService->record('backups.restored', SystemSetting::class, $request->user(), [
            'backup' => $backup,
            'target' => $target,
            'successful' => $result->successful(),
        ], null, $request);

        if (! $result->successful()) {
            return ApiResponse::error('backup_restore_failed', $output ?: 'Backup restore mislukt.', 500);
        }

        return ApiResponse::success([
            'backup' => $backup,
            'state' => 'restored',
            'output' => $output,
        ]);
    }

    private function backupRoot(?string $target = null): string
    {
        $target ??= SystemSetting::string('backup.target', 'local') ?? 'local';
        if ($target === 'samba') {
            return rtrim(SystemSetting::string('backup.samba.mount', '/mnt/dis-backup') ?? '/mnt/dis-backup', '/');
        }

        $configured = SystemSetting::string('backup.local_path', null) ?? config('filesystems.disks.backups.root');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, '/')
            : rtrim(base_path('../..'), '/').'/backup';
    }

    private function scriptPath(string $script): string
    {
        return rtrim(base_path('../..'), '/').'/scripts/'.$script;
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsState(): array
    {
        return [
            'target' => SystemSetting::string('backup.target', 'local') ?? 'local',
            'local_path' => SystemSetting::string('backup.local_path', self::DEFAULT_LOCAL_PATH) ?? self::DEFAULT_LOCAL_PATH,
            'samba_share' => SystemSetting::string('backup.samba.share', '') ?? '',
            'samba_mount' => SystemSetting::string('backup.samba.mount', '/mnt/dis-backup') ?? '/mnt/dis-backup',
            'samba_username' => SystemSetting::string('backup.samba.username', '') ?? '',
            'samba_password_configured' => SystemSetting::string(self::PASSWORD_KEY, '') !== '',
            'samba_domain' => SystemSetting::string('backup.samba.domain', '') ?? '',
            'samba_version' => SystemSetting::string('backup.samba.version', '3.1.1') ?? '3.1.1',
            'samba_mounted' => $this->isMounted(SystemSetting::string('backup.samba.mount', '/mnt/dis-backup') ?? '/mnt/dis-backup'),
        ];
    }

    private function putSetting(string $key, mixed $value, Request $request, bool $sensitive = false): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'is_sensitive' => $sensitive,
                'updated_by' => $request->user()?->id,
            ],
        );
    }

    private function writeRuntimeConfig(?string $targetOverride = null): void
    {
        $target = $targetOverride ?? SystemSetting::string('backup.target', 'local') ?? 'local';
        $lines = [
            'BACKUP_TARGET='.$this->shellValue($target),
            'BACKUP_ROOT='.$this->shellValue(SystemSetting::string('backup.local_path', self::DEFAULT_LOCAL_PATH) ?? self::DEFAULT_LOCAL_PATH),
            'BACKUP_SAMBA_SHARE='.$this->shellValue(SystemSetting::string('backup.samba.share', '') ?? ''),
            'BACKUP_SAMBA_MOUNT='.$this->shellValue(SystemSetting::string('backup.samba.mount', '/mnt/dis-backup') ?? '/mnt/dis-backup'),
            'BACKUP_SAMBA_USERNAME='.$this->shellValue(SystemSetting::string('backup.samba.username', '') ?? ''),
            'BACKUP_SAMBA_PASSWORD='.$this->shellValue(SystemSetting::string(self::PASSWORD_KEY, '') ?? ''),
            'BACKUP_SAMBA_DOMAIN='.$this->shellValue(SystemSetting::string('backup.samba.domain', '') ?? ''),
            'BACKUP_SAMBA_VERSION='.$this->shellValue(SystemSetting::string('backup.samba.version', '3.1.1') ?? '3.1.1'),
        ];
        $path = storage_path('app/backup-config.env');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        file_put_contents($path, implode("\n", $lines)."\n", LOCK_EX);
        chmod($path, 0640);
    }

    private function shellValue(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }

    private function isMounted(string $path): bool
    {
        $result = Process::run(['mountpoint', '-q', $path]);

        return $result->successful();
    }

    private function requestTarget(Request $request): string
    {
        $data = $request->validate([
            'target' => ['nullable', 'string', 'in:local,samba'],
        ]);

        return $data['target'] ?? SystemSetting::string('backup.target', 'local') ?? 'local';
    }

    private function ensureTargetReady(string $target): void
    {
        if ($target !== 'samba') {
            return;
        }

        $share = trim(SystemSetting::string('backup.samba.share', '') ?? '');
        $username = trim(SystemSetting::string('backup.samba.username', '') ?? '');
        $password = SystemSetting::string(self::PASSWORD_KEY, '') ?? '';
        if ($share === '' || $username === '' || $password === '') {
            throw ValidationException::withMessages([
                'target' => ['Samba backups zijn nog niet volledig ingesteld.'],
            ]);
        }
    }

    private function backupPath(string $backup, ?string $target = null): string
    {
        if (! $this->validBackupId($backup)) {
            abort(404);
        }

        $root = $this->backupRoot($target);
        $path = $root.'/'.$backup;
        if (! str_starts_with($path, $root.'/')) {
            abort(404);
        }

        return $path;
    }

    private function validBackupId(string $backup): bool
    {
        return preg_match('/^\d{8}T\d{6}Z$/', $backup) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function backupSummary(string $id, string $path, string $target): array
    {
        $manifestPath = $path.'/manifest.json';
        $manifest = [];
        if (is_file($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            $manifest = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => $id,
            'target' => $target,
            'created_at' => $manifest['created_at'] ?? $id,
            'database' => $manifest['database'] ?? null,
            'host' => $manifest['host'] ?? null,
            'version' => $manifest['version'] ?? null,
            'git_commit' => $manifest['git_commit'] ?? null,
            'includes' => is_array($manifest['includes'] ?? null) ? $manifest['includes'] : [],
            'size_bytes' => $this->directorySize($path),
            'has_manifest' => is_file($manifestPath),
            'has_checksums' => is_file($path.'/SHA256SUMS'),
        ];
    }

    private function directorySize(string $path): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    private function cleanOutput(string $output): string
    {
        $output = preg_replace('/((?:password|secret|token|api[_-]?key)[\'"\s:=]+)[^\'"\s,}]+/i', '$1[redacted]', $output) ?? $output;

        return trim($output);
    }
}
