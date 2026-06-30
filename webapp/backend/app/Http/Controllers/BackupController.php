<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BackupReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class BackupController extends Controller
{
    private const RESTORE_CONFIRMATION = 'HERSTEL BACKUP';
    private const DEFAULT_LOCAL_PATH = '/opt/dis-data/backup';
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
            'report_recipients' => $this->reportRecipients(),
            'confirmation_text' => self::RESTORE_CONFIRMATION,
            'backups' => $backups,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'in:local,samba'],
            'samba_server' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'samba_share_name' => ['nullable', 'string', 'max:255', 'regex:/^[^\/\\\\]+$/'],
            'samba_share' => ['nullable', 'string', 'max:255'],
            'samba_mount' => ['nullable', 'string', 'in:/mnt/dis-backup'],
            'samba_username' => ['nullable', 'string', 'max:255'],
            'samba_password' => ['nullable', 'string', 'max:2000'],
            'samba_domain' => ['nullable', 'string', 'max:255'],
            'samba_version' => ['nullable', 'string', 'in:3.1.1,3.0,2.1,2.0,1.0'],
            'auto_enabled' => ['required', 'boolean'],
            'auto_frequency' => ['required', 'string', 'in:daily,weekly'],
            'auto_day_of_week' => ['required', 'integer', 'between:1,7'],
            'auto_time' => ['required', 'date_format:H:i'],
            'retention_count' => ['required', 'integer', 'between:0,365'],
            'backup_report_success_user_ids' => ['nullable', 'array'],
            'backup_report_success_user_ids.*' => ['ulid', 'exists:users,id'],
            'backup_report_failed_user_ids' => ['nullable', 'array'],
            'backup_report_failed_user_ids.*' => ['ulid', 'exists:users,id'],
        ]);

        if ($data['target'] === 'samba') {
            foreach (['samba_server', 'samba_share_name', 'samba_mount', 'samba_username'] as $field) {
                if (trim((string) ($data[$field] ?? '')) === '') {
                    throw ValidationException::withMessages([$field => ['Dit veld is verplicht voor Samba backups.']]);
                }
            }

            if (trim((string) ($data['samba_password'] ?? '')) === '' && SystemSetting::string(self::PASSWORD_KEY, '') === '') {
                throw ValidationException::withMessages(['samba_password' => ['Dit veld is verplicht zolang er nog geen Samba wachtwoord is opgeslagen.']]);
            }
        }

        $server = trim((string) ($data['samba_server'] ?? ''));
        $shareName = trim((string) ($data['samba_share_name'] ?? ''));
        $sharePath = $server !== '' && $shareName !== '' ? '//'.$server.'/'.$shareName : '';

        $this->putSetting('backup.target', $data['target'], $request);
        $this->putSetting('backup.local_path', self::DEFAULT_LOCAL_PATH, $request);
        $this->putSetting('backup.samba.server', $server, $request);
        $this->putSetting('backup.samba.share_name', $shareName, $request);
        $this->putSetting('backup.samba.share', $sharePath, $request);
        $this->putSetting('backup.samba.mount', trim((string) ($data['samba_mount'] ?? '')) ?: '/mnt/dis-backup', $request);
        $this->putSetting('backup.samba.username', trim((string) ($data['samba_username'] ?? '')), $request);
        $this->putSetting('backup.samba.domain', trim((string) ($data['samba_domain'] ?? '')), $request);
        $this->putSetting('backup.samba.version', trim((string) ($data['samba_version'] ?? '')) ?: '3.1.1', $request);
        $this->putSetting('backup.auto.enabled', (bool) $data['auto_enabled'], $request);
        $this->putSetting('backup.auto.frequency', $data['auto_frequency'], $request);
        $this->putSetting('backup.auto.day_of_week', (int) $data['auto_day_of_week'], $request);
        $this->putSetting('backup.auto.time', $data['auto_time'], $request);
        $this->putSetting('backup.retention_count', (int) $data['retention_count'], $request);
        if (array_key_exists('samba_password', $data) && trim((string) $data['samba_password']) !== '') {
            $this->putSetting(self::PASSWORD_KEY, (string) $data['samba_password'], $request, true);
        }
        $this->updateReportRecipients(
            $data['backup_report_success_user_ids'] ?? null,
            $data['backup_report_failed_user_ids'] ?? null,
        );

        $this->writeRuntimeConfig();
        $this->auditService->record('backups.settings_updated', SystemSetting::class, $request->user(), [
            'target' => $data['target'],
            'auto_enabled' => (bool) $data['auto_enabled'],
            'auto_frequency' => $data['auto_frequency'],
            'retention_count' => (int) $data['retention_count'],
            'samba_server' => $data['target'] === 'samba' ? $server : null,
            'samba_share_name' => $data['target'] === 'samba' ? $shareName : null,
        ], null, $request);

        return $this->index();
    }

    public function sambaShares(Request $request): JsonResponse
    {
        $data = $request->validate([
            'samba_server' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'samba_username' => ['required', 'string', 'max:255'],
            'samba_password' => ['nullable', 'string', 'max:2000'],
            'samba_domain' => ['nullable', 'string', 'max:255'],
            'samba_version' => ['nullable', 'string', 'in:3.1.1,3.0,2.1,2.0,1.0'],
        ]);

        if (! is_executable('/usr/bin/smbclient') && ! is_executable('/bin/smbclient')) {
            return ApiResponse::error('smbclient_missing', 'Samba shares ophalen vereist smbclient op de server. Voer een systeemupdate uit zodat dit pakket wordt geinstalleerd.', 500);
        }

        $password = (string) ($data['samba_password'] ?? '');
        if (trim($password) === '') {
            $password = SystemSetting::string(self::PASSWORD_KEY, '') ?? '';
        }
        if ($password === '') {
            throw ValidationException::withMessages(['samba_password' => ['Vul het Samba wachtwoord in om shares op te halen.']]);
        }

        $credentialsFile = tempnam(sys_get_temp_dir(), 'dis-smb-');
        if ($credentialsFile === false) {
            return ApiResponse::error('smb_credentials_failed', 'Tijdelijk credentialsbestand kon niet worden gemaakt.', 500);
        }

        try {
            file_put_contents($credentialsFile, $this->smbCredentialsContent((string) $data['samba_username'], $password, (string) ($data['samba_domain'] ?? '')), LOCK_EX);
            chmod($credentialsFile, 0600);

            $command = [
                is_executable('/usr/bin/smbclient') ? '/usr/bin/smbclient' : '/bin/smbclient',
                '-L',
                '//'.$data['samba_server'],
                '-A',
                $credentialsFile,
                '-m',
                $this->smbClientProtocol((string) ($data['samba_version'] ?? '3.1.1')),
                '-g',
            ];
            $result = Process::timeout(30)->run($command);
        } finally {
            @unlink($credentialsFile);
        }

        $output = $this->cleanOutput($result->output().$result->errorOutput());
        if (! $result->successful()) {
            return ApiResponse::error('smb_shares_failed', $output ?: 'Samba shares konden niet worden opgehaald.', 422);
        }

        return ApiResponse::success([
            'shares' => $this->parseSmbShares($result->output(), (string) $data['samba_server']),
        ]);
    }

    public function create(Request $request, BackupReportService $backupReports): JsonResponse
    {
        $target = $this->requestTarget($request);
        $this->ensureTargetReady($target);
        $this->writeRuntimeConfig($target);
        $result = Process::timeout(900)->run(['sudo', '-n', $this->bashBinary(), $this->scriptPath('backup.sh')]);
        $output = $this->cleanOutput($result->output().$result->errorOutput());
        $reportRecipients = $result->successful()
            ? $backupReports->sendSuccess($target, $output !== '' ? $output : 'Manual backup completed.')
            : $backupReports->sendFailed($target, $result->exitCode(), $output !== '' ? $output : 'Manual backup failed.');

        $this->auditService->record('backups.created', SystemSetting::class, $request->user(), [
            'target' => $target,
            'successful' => $result->successful(),
            'report_recipients' => $reportRecipients,
        ], null, $request);

        if (! $result->successful()) {
            return ApiResponse::error('backup_failed', $output ?: 'Backup maken mislukt.', 500);
        }

        return ApiResponse::success([
            'output' => $output,
            'state' => 'succeeded',
            'report_recipients' => $reportRecipients,
            'backups' => $this->index()->getData(true)['data']['backups'] ?? [],
        ], 201);
    }

    public function verify(Request $request, string $backup): JsonResponse
    {
        $target = $this->requestTarget($request);
        $this->ensureTargetReady($target);
        $this->writeRuntimeConfig($target);
        $path = $this->backupPath($backup, $target);
        $result = Process::timeout(600)->run(['sudo', '-n', '/usr/local/bin/dis-backup-verify', $path]);
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
        $result = Process::timeout(1200)->run(['sudo', '-n', '/usr/local/bin/dis-backup-restore', $path]);
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

    public function uploadRestore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string'],
            'backup' => ['required', 'file', 'max:2097152'],
        ]);
        if ($data['confirmation'] !== self::RESTORE_CONFIRMATION) {
            throw ValidationException::withMessages(['confirmation' => ['Bevestigingstekst klopt niet.']]);
        }

        $file = $request->file('backup');
        if ($file === null || strtolower((string) $file->getClientOriginalExtension()) !== 'zip') {
            throw ValidationException::withMessages(['backup' => ['Upload een ZIP-bestand met een DIS backup.']]);
        }
        $uploadedPath = $file->getRealPath();
        if ($uploadedPath === false) {
            throw ValidationException::withMessages(['backup' => ['Uploadbestand kon niet worden gelezen.']]);
        }

        $root = $this->backupRoot('local');
        if (! is_dir($root) && ! mkdir($root, 0750, true) && ! is_dir($root)) {
            return ApiResponse::error('backup_upload_failed', 'Lokale backupmap kon niet worden gemaakt.', 500);
        }

        $backupId = $this->nextImportBackupId($root);
        $path = $root.'/'.$backupId;
        if (! mkdir($path, 0750, true) && ! is_dir($path)) {
            return ApiResponse::error('backup_upload_failed', 'Upload backupmap kon niet worden gemaakt.', 500);
        }

        try {
            $this->extractBackupZip($uploadedPath, $path);
            $verify = Process::timeout(600)->run(['sudo', '-n', '/usr/local/bin/dis-backup-verify', $path]);
            $verifyOutput = $this->cleanOutput($verify->output().$verify->errorOutput());
            if (! $verify->successful()) {
                $this->deleteDirectory($path, $root);
                $this->auditService->record('backups.upload_restore_failed', SystemSetting::class, $request->user(), [
                    'backup' => $backupId,
                    'successful' => false,
                    'stage' => 'verify',
                ], null, $request);

                return ApiResponse::error('backup_upload_verify_failed', $verifyOutput ?: 'Geuploade backup is niet geldig.', 422);
            }

            $this->writeRuntimeConfig('local');
            $restore = Process::timeout(1200)->run(['sudo', '-n', '/usr/local/bin/dis-backup-restore', $path]);
            $restoreOutput = $this->cleanOutput($restore->output().$restore->errorOutput());
            $output = trim($verifyOutput."\n".$restoreOutput);

            $this->auditService->record('backups.upload_restored', SystemSetting::class, $request->user(), [
                'backup' => $backupId,
                'filename' => $file->getClientOriginalName(),
                'successful' => $restore->successful(),
            ], null, $request);

            if (! $restore->successful()) {
                return ApiResponse::error('backup_upload_restore_failed', $restoreOutput ?: 'Backup restore mislukt.', 500);
            }

            return ApiResponse::success([
                'backup' => $backupId,
                'state' => 'restored',
                'output' => $output,
                'backups' => $this->index()->getData(true)['data']['backups'] ?? [],
            ]);
        } catch (ValidationException $exception) {
            $this->deleteDirectory($path, $root);
            throw $exception;
        } catch (\Throwable $exception) {
            $this->deleteDirectory($path, $root);
            report($exception);

            return ApiResponse::error('backup_upload_failed', 'Geuploade backup kon niet worden verwerkt.', 500);
        }
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
        $path = dirname(base_path(), 2).'/scripts/'.$script;

        return realpath($path) ?: $path;
    }

    private function bashBinary(): string
    {
        return is_executable('/usr/bin/bash') ? '/usr/bin/bash' : '/bin/bash';
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsState(): array
    {
        $legacyShare = SystemSetting::string('backup.samba.share', '') ?? '';
        [$server, $shareName] = $this->storedSambaServerAndShare($legacyShare);

        return [
            'target' => SystemSetting::string('backup.target', 'local') ?? 'local',
            'local_path' => SystemSetting::string('backup.local_path', self::DEFAULT_LOCAL_PATH) ?? self::DEFAULT_LOCAL_PATH,
            'samba_server' => $server,
            'samba_share_name' => $shareName,
            'samba_share' => $server !== '' && $shareName !== '' ? '//'.$server.'/'.$shareName : $legacyShare,
            'samba_mount' => SystemSetting::string('backup.samba.mount', '/mnt/dis-backup') ?? '/mnt/dis-backup',
            'samba_username' => SystemSetting::string('backup.samba.username', '') ?? '',
            'samba_password_configured' => SystemSetting::string(self::PASSWORD_KEY, '') !== '',
            'samba_domain' => SystemSetting::string('backup.samba.domain', '') ?? '',
            'samba_version' => SystemSetting::string('backup.samba.version', '3.1.1') ?? '3.1.1',
            'samba_mounted' => $this->isMounted(SystemSetting::string('backup.samba.mount', '/mnt/dis-backup') ?? '/mnt/dis-backup'),
            'auto_enabled' => SystemSetting::boolean('backup.auto.enabled', false),
            'auto_frequency' => SystemSetting::string('backup.auto.frequency', 'daily') ?? 'daily',
            'auto_day_of_week' => SystemSetting::integer('backup.auto.day_of_week', 1),
            'auto_time' => SystemSetting::string('backup.auto.time', '02:15') ?? '02:15',
            'retention_count' => SystemSetting::integer('backup.retention_count', 7),
            'auto_last_run_at' => SystemSetting::string('backup.auto.last_run_at'),
        ];
    }

    /**
     * @return list<array{id: string, name: string, email: string, success: bool, failed: bool}>
     */
    private function reportRecipients(): array
    {
        return User::query()
            ->where('account_status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'mail_preferences'])
            ->map(function (User $user): array {
                return [
                    'id' => (string) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'success' => $user->wantsBackupReport('success'),
                    'failed' => $user->wantsBackupReport('failed'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, string>|null $successUserIds
     * @param array<int, string>|null $failedUserIds
     */
    private function updateReportRecipients(?array $successUserIds, ?array $failedUserIds): void
    {
        if ($successUserIds === null && $failedUserIds === null) {
            return;
        }

        $success = collect($successUserIds ?? [])->unique()->values();
        $failed = collect($failedUserIds ?? [])->unique()->values();

        User::query()
            ->where('account_status', 'active')
            ->get()
            ->each(function (User $user) use ($success, $failed): void {
                $preferences = is_array($user->mail_preferences) ? $user->mail_preferences : [];
                $preferences['backup_report'] = [
                    'success' => $success->contains((string) $user->id),
                    'failed' => $failed->contains((string) $user->id),
                ];

                $user->forceFill(['mail_preferences' => $preferences])->save();
            });
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
            'BACKUP_RETENTION_COUNT='.$this->shellValue((string) max(0, SystemSetting::integer('backup.retention_count', 7))),
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

        $legacyShare = SystemSetting::string('backup.samba.share', '') ?? '';
        [$server, $shareName] = $this->storedSambaServerAndShare($legacyShare);
        $share = $server !== '' && $shareName !== '' ? '//'.$server.'/'.$shareName : trim($legacyShare);
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

    private function nextImportBackupId(string $root): string
    {
        $now = now('UTC');
        for ($offset = 0; $offset < 120; $offset++) {
            $id = $now->copy()->addSeconds($offset)->format('Ymd\THis\Z');
            if (! is_dir($root.'/'.$id)) {
                return $id;
            }
        }

        throw ValidationException::withMessages(['backup' => ['Er kon geen unieke backupnaam worden gemaakt.']]);
    }

    private function extractBackupZip(string $zipPath, string $targetPath): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['backup' => ['ZIP ondersteuning ontbreekt op de server.']]);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw ValidationException::withMessages(['backup' => ['ZIP-bestand kon niet worden geopend.']]);
        }

        try {
            $prefix = $this->backupZipPrefix($zip);
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->getNameIndex($index);
                if (! is_string($entry)) {
                    continue;
                }

                $relative = $this->safeZipRelativePath($entry, $prefix);
                if ($relative === null) {
                    continue;
                }

                $destination = $targetPath.'/'.$relative;
                if (str_ends_with($entry, '/')) {
                    $this->ensureDirectory($destination);
                    continue;
                }

                $stream = $zip->getStream($entry);
                if ($stream === false) {
                    throw ValidationException::withMessages(['backup' => ['ZIP-bestand bevat een onleesbaar bestand.']]);
                }

                $this->ensureDirectory(dirname($destination));
                $output = fopen($destination, 'wb');
                if ($output === false) {
                    fclose($stream);
                    throw ValidationException::withMessages(['backup' => ['Backupbestand kon niet worden weggeschreven.']]);
                }

                stream_copy_to_stream($stream, $output);
                fclose($stream);
                fclose($output);
                chmod($destination, 0640);
            }
        } finally {
            $zip->close();
        }

        foreach (['database.dump', 'storage.tar.gz', 'source.tar.gz', 'env.backup', 'SHA256SUMS', 'manifest.json'] as $required) {
            if (! is_file($targetPath.'/'.$required)) {
                throw ValidationException::withMessages(['backup' => ['ZIP-bestand bevat geen volledige DIS backup.']]);
            }
        }
    }

    private function backupZipPrefix(ZipArchive $zip): string
    {
        $required = ['database.dump', 'storage.tar.gz', 'source.tar.gz', 'env.backup', 'SHA256SUMS', 'manifest.json'];
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name)) {
                $names[] = ltrim(str_replace('\\', '/', $name), '/');
            }
        }

        $prefixes = array_values(array_unique(array_filter(array_map(
            fn (string $name): string => explode('/', $name, 2)[0] ?? '',
            $names,
        ))));
        array_unshift($prefixes, '');

        foreach ($prefixes as $prefix) {
            $base = $prefix === '' ? '' : $prefix.'/';
            $hasAll = true;
            foreach ($required as $requiredFile) {
                if (! in_array($base.$requiredFile, $names, true)) {
                    $hasAll = false;
                    break;
                }
            }
            if ($hasAll) {
                return $base;
            }
        }

        throw ValidationException::withMessages(['backup' => ['ZIP-bestand bevat geen volledige DIS backup.']]);
    }

    private function safeZipRelativePath(string $entry, string $prefix): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', $entry), '/');
        if ($prefix !== '' && ! str_starts_with($normalized, $prefix)) {
            return null;
        }

        $relative = $prefix === '' ? $normalized : substr($normalized, strlen($prefix));
        $relative = trim($relative, '/');
        if ($relative === '' || str_starts_with($relative, '__MACOSX/')) {
            return null;
        }

        if (in_array('..', explode('/', $relative), true) || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            throw ValidationException::withMessages(['backup' => ['ZIP-bestand bevat onveilige paden.']]);
        }

        return $relative;
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0750, true) && ! is_dir($path)) {
            throw ValidationException::withMessages(['backup' => ['Backupmap kon niet worden aangemaakt.']]);
        }

        chmod($path, 0750);
    }

    private function deleteDirectory(string $path, string $root): void
    {
        $root = rtrim(realpath($root) ?: $root, '/');
        $path = rtrim(realpath($path) ?: $path, '/');
        if ($path === $root || ! str_starts_with($path, $root.'/') || ! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function backupSummary(string $id, string $path, string $target): array
    {
        $manifestPath = $path.'/manifest.json';
        $manifest = [];
        if (is_file($manifestPath) && is_readable($manifestPath)) {
            $decoded = json_decode((string) file_get_contents($manifestPath), true);
            $manifest = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => $id,
            'target' => $target,
            'created_at' => is_string($manifest['created_at'] ?? null) ? $manifest['created_at'] : $this->createdAtFromBackupId($id),
            'database' => $manifest['database'] ?? null,
            'host' => $manifest['host'] ?? null,
            'version' => $manifest['version'] ?? null,
            'git_commit' => $manifest['git_commit'] ?? null,
            'includes' => is_array($manifest['includes'] ?? null) ? $manifest['includes'] : [],
            'size_bytes' => $this->directorySize($path),
            'has_manifest' => is_file($manifestPath) && is_readable($manifestPath),
            'has_checksums' => is_file($path.'/SHA256SUMS') && is_readable($path.'/SHA256SUMS'),
        ];
    }

    private function directorySize(string $path): int
    {
        if (! is_readable($path)) {
            return 0;
        }

        $size = 0;
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\UnexpectedValueException) {
            return 0;
        }

        return $size;
    }

    private function createdAtFromBackupId(string $id): string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/', $id, $matches) !== 1) {
            return $id;
        }

        return sprintf(
            '%s-%s-%sT%s:%s:%s+00:00',
            $matches[1],
            $matches[2],
            $matches[3],
            $matches[4],
            $matches[5],
            $matches[6],
        );
    }

    private function cleanOutput(string $output): string
    {
        $output = preg_replace('/((?:password|secret|token|api[_-]?key)[\'"\s:=]+)[^\'"\s,}]+/i', '$1[redacted]', $output) ?? $output;

        return trim($output);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storedSambaServerAndShare(string $legacyShare): array
    {
        $server = SystemSetting::string('backup.samba.server', '') ?? '';
        $shareName = SystemSetting::string('backup.samba.share_name', '') ?? '';
        if ($server !== '' || $shareName !== '') {
            return [$server, $shareName];
        }

        if (preg_match('#^//([^/]+)/(.+)$#', $legacyShare, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return ['', ''];
    }

    private function smbClientProtocol(string $mountVersion): string
    {
        return match ($mountVersion) {
            '1.0' => 'NT1',
            '2.0', '2.1' => 'SMB2',
            default => 'SMB3',
        };
    }

    private function smbCredentialsContent(string $username, string $password, string $domain): string
    {
        $lines = [
            'username='.$username,
            'password='.$password,
        ];

        if (trim($domain) !== '') {
            $lines[] = 'domain='.trim($domain);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<array{name: string, path: string, comment: string|null}>
     */
    private function parseSmbShares(string $output, string $server): array
    {
        $shares = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $parts = explode('|', trim($line));
            if (count($parts) < 2 || strtolower($parts[0]) !== 'disk') {
                continue;
            }

            $name = trim($parts[1]);
            if ($name === '' || in_array(strtolower($name), ['ipc$', 'print$'], true)) {
                continue;
            }

            $shares[] = [
                'name' => $name,
                'path' => '//'.$server.'/'.$name,
                'comment' => isset($parts[2]) && trim($parts[2]) !== '' ? trim($parts[2]) : null,
            ];
        }

        usort($shares, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $shares;
    }
}
