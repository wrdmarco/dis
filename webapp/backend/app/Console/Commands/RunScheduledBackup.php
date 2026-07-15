<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\BackupReportOrigin;
use App\Services\BackupReportService;
use App\Services\BackupRequestService;
use App\Support\ApiDateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class RunScheduledBackup extends Command
{
    protected $signature = 'dis:run-scheduled-backup';

    protected $description = 'Run the configured automatic DIS backup.';

    public function handle(
        AuditService $auditService,
        BackupReportService $backupReports,
        BackupRequestService $backupRequests,
    ): int {
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
                'report_recipients' => $backupReports->sendFailed($target, 1, $message, BackupReportOrigin::Automatic),
            ]);
            $this->error($message);

            return self::FAILURE;
        }

        $this->writeRuntimeConfig($target);
        $result = $backupRequests->create($target, null);
        $output = trim($result['output']);

        if ($result['exit_code'] !== 0) {
            $cleanOutput = $this->cleanOutput($output !== '' ? $output : 'Automatic backup failed.');
            $auditService->record('backups.automatic_failed', SystemSetting::class, null, [
                'target' => $target,
                'exit_code' => $result['exit_code'],
                'request_id' => $result['request_id'],
                'output' => mb_substr($cleanOutput, 0, 1000),
                'report_recipients' => $backupReports->sendFailed(
                    $target,
                    $result['exit_code'],
                    $cleanOutput,
                    BackupReportOrigin::Automatic,
                ),
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
            'request_id' => $result['request_id'],
            'retention_count' => max(0, SystemSetting::integer('backup.retention_count', 7)),
            'report_recipients' => $backupReports->sendSuccess(
                $target,
                $this->cleanOutput($output !== '' ? $output : 'Automatic backup completed.'),
                BackupReportOrigin::Automatic,
            ),
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

    private function cleanOutput(string $output): string
    {
        $output = preg_replace('/((?:password|secret|token|api[_-]?key)[\'"\s:=]+)[^\'"\s,}]+/i', '$1[redacted]', $output) ?? $output;

        return trim($output);
    }
}
