<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\BackupReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

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
        $result = Process::timeout(900)->run(['sudo', '-n', 'bash', $this->scriptPath('backup.sh')]);
        $output = trim($result->output().$result->errorOutput());

        if (! $result->successful()) {
            $cleanOutput = $this->cleanOutput($output !== '' ? $output : 'Automatic backup failed.');
            $auditService->record('backups.automatic_failed', SystemSetting::class, null, [
                'target' => $target,
                'exit_code' => $result->exitCode(),
                'output' => mb_substr($cleanOutput, 0, 1000),
                'report_recipients' => $backupReports->sendFailed($target, $result->exitCode(), $cleanOutput),
            ]);
            $this->error($cleanOutput);

            return self::FAILURE;
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => 'backup.auto.last_run_at'],
            ['value' => now()->toIso8601String(), 'is_sensitive' => false, 'updated_by' => null],
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
        $lines = [
            'BACKUP_TARGET='.$this->shellValue($target),
            'BACKUP_ROOT='.$this->shellValue(SystemSetting::string('backup.local_path', '/opt/dis/backup') ?? '/opt/dis/backup'),
            'BACKUP_RETENTION_COUNT='.$this->shellValue((string) max(0, SystemSetting::integer('backup.retention_count', 7))),
            'BACKUP_SAMBA_SHARE='.$this->shellValue(SystemSetting::string('backup.samba.share', '') ?? ''),
            'BACKUP_SAMBA_MOUNT='.$this->shellValue(SystemSetting::string('backup.samba.mount', '/mnt/dis-backup') ?? '/mnt/dis-backup'),
            'BACKUP_SAMBA_USERNAME='.$this->shellValue(SystemSetting::string('backup.samba.username', '') ?? ''),
            'BACKUP_SAMBA_PASSWORD='.$this->shellValue(SystemSetting::string('backup.samba.password', '') ?? ''),
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

    private function scriptPath(string $script): string
    {
        return rtrim(base_path('../..'), '/').'/scripts/'.$script;
    }

    private function shellValue(string $value): string
    {
        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }

    private function cleanOutput(string $output): string
    {
        $output = preg_replace('/((?:password|secret|token|api[_-]?key)[\'"\s:=]+)[^\'"\s,}]+/i', '$1[redacted]', $output) ?? $output;

        return trim($output);
    }
}
