<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\BackupReportService;
use App\Support\ApiDateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class RunScheduledBackup extends Command
{
    protected $signature = 'dis:run-scheduled-backup';

    protected $description = 'Run the configured automatic DIS backup.';

    public function handle(AuditService $auditService, BackupReportService $backupReports): int
    {
        if (! $this->backupDue()) {
            $this->info('Automatic backup checked. Not due.');

            return self::SUCCESS;
        }

        $target = SystemSetting::string('backup.target', 'local') ?? 'local';
        if ($target === 'samba' && ! $this->sambaReady()) {
            $message = 'Automatic backup skipped. Samba backups are not fully configured.';
            $auditService->record('backups.automatic_skipped', SystemSetting::class, null, [
                'target' => $target,
                'reason' => 'samba_not_configured',
                'report_recipients' => $backupReports->sendFailed($target, 1, $message),
            ]);
            $this->error($message);

            return self::FAILURE;
        }

        $this->writeRuntimeConfig($target);
        $result = $this->runBackupRequest('create', $target, null, 900);
        $output = trim($result['output']);

        if ($result['exit_code'] !== 0) {
            $cleanOutput = $this->cleanOutput($output !== '' ? $output : 'Automatic backup failed.');
            $auditService->record('backups.automatic_failed', SystemSetting::class, null, [
                'target' => $target,
                'exit_code' => $result['exit_code'],
                'output' => mb_substr($cleanOutput, 0, 1000),
                'report_recipients' => $backupReports->sendFailed($target, $result['exit_code'], $cleanOutput),
            ]);
            $this->error($cleanOutput);

            return self::FAILURE;
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => 'backup.auto.last_run_at'],
            ['value' => ApiDateTime::now(), 'is_sensitive' => false, 'updated_by' => null],
        );

        $auditService->record('backups.automatic_created', SystemSetting::class, null, [
            'target' => $target,
            'retention_count' => max(0, SystemSetting::integer('backup.retention_count', 7)),
            'report_recipients' => $backupReports->sendSuccess($target, $this->cleanOutput($output !== '' ? $output : 'Automatic backup completed.')),
        ]);

        $this->info($this->cleanOutput($output !== '' ? $output : 'Automatic backup completed.'));

        return self::SUCCESS;
    }

    private function backupDue(): bool
    {
        if (! SystemSetting::boolean('backup.auto.enabled', false)) {
            return false;
        }

        $now = now();
        $frequency = SystemSetting::string('backup.auto.frequency', 'daily') ?? 'daily';
        $time = SystemSetting::string('backup.auto.time', '02:15') ?? '02:15';
        if ($time !== $now->format('H:i')) {
            return false;
        }

        if ($frequency === 'weekly' && SystemSetting::integer('backup.auto.day_of_week', 1) !== $now->dayOfWeekIso) {
            return false;
        }

        $runKey = 'backup-auto:'.$now->format('Y-m-d-H-i');

        return Cache::add($runKey, true, $now->copy()->addHours(2));
    }

    private function sambaReady(): bool
    {
        return trim(SystemSetting::string('backup.samba.share', '') ?? '') !== ''
            && trim(SystemSetting::string('backup.samba.username', '') ?? '') !== ''
            && (SystemSetting::string('backup.samba.password', '') ?? '') !== '';
    }

    private function writeRuntimeConfig(string $target): void
    {
        $config = [
            'BACKUP_TARGET' => $target,
            'BACKUP_ROOT' => SystemSetting::string('backup.local_path', '/opt/dis-data/backup') ?? '/opt/dis-data/backup',
            'BACKUP_RETENTION_COUNT' => (string) max(0, SystemSetting::integer('backup.retention_count', 7)),
            'BACKUP_ENCRYPTION_KEY_FILE' => rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/').'/secrets/backup-encryption.key',
            'BACKUP_SAMBA_SHARE' => SystemSetting::string('backup.samba.share', '') ?? '',
            'BACKUP_SAMBA_MOUNT' => SystemSetting::string('backup.samba.mount', '/mnt/dis-backup') ?? '/mnt/dis-backup',
            'BACKUP_SAMBA_USERNAME' => SystemSetting::string('backup.samba.username', '') ?? '',
            'BACKUP_SAMBA_PASSWORD' => SystemSetting::string('backup.samba.password', '') ?? '',
            'BACKUP_SAMBA_DOMAIN' => SystemSetting::string('backup.samba.domain', '') ?? '',
            'BACKUP_SAMBA_VERSION' => SystemSetting::string('backup.samba.version', '3.1.1') ?? '3.1.1',
        ];

        $path = storage_path('app/backup-config.json');
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        $temporary = tempnam($directory, '.backup-config-');
        if ($temporary === false) {
            throw new \RuntimeException('Backup runtime configuration could not be created.');
        }
        try {
            file_put_contents($temporary, json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n", LOCK_EX);
            chmod($temporary, 0640);
            if (! rename($temporary, $path)) {
                throw new \RuntimeException('Backup runtime configuration could not be published.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return array{exit_code: int, output: string}
     */
    private function runBackupRequest(string $operation, string $target, ?string $backupPath, int $timeoutSeconds): array
    {
        $root = $this->backupRequestRoot();
        if (! is_dir($root) || ! is_writable($root)) {
            return ['exit_code' => 1, 'output' => 'Beveiligde backup request map is niet beschikbaar.'];
        }

        $id = bin2hex(random_bytes(16));
        $temporary = $root.'/'.$id.'.tmp';
        $pending = $root.'/'.$id.'.pending';
        $result = $root.'/'.$id.'.result';
        file_put_contents($temporary, json_encode([
            'operation' => $operation,
            'target' => $target,
            'backup_path' => $backupPath,
            'actor_id' => null,
            'created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ], JSON_THROW_ON_ERROR)."\n", LOCK_EX);
        @chmod($temporary, 0660);
        rename($temporary, $pending);

        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($result)) {
                $decoded = json_decode((string) file_get_contents($result), true);
                @unlink($result);

                return [
                    'exit_code' => (int) ($decoded['exit_code'] ?? 1),
                    'output' => is_string($decoded['output'] ?? null) ? $decoded['output'] : 'Backup runner gaf geen geldige uitvoer terug.',
                ];
            }

            usleep(500000);
        }

        @unlink($pending);

        return ['exit_code' => 124, 'output' => 'Backup runner reageerde niet binnen de verwachte tijd. Controleer de DIS backup request service.'];
    }

    private function backupRequestRoot(): string
    {
        $dataPath = rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/');

        return $dataPath.'/backup-requests';
    }

    private function cleanOutput(string $output): string
    {
        $output = preg_replace('/((?:password|secret|token|api[_-]?key)[\'"\s:=]+)[^\'"\s,}]+/i', '$1[redacted]', $output) ?? $output;

        return trim($output);
    }
}
