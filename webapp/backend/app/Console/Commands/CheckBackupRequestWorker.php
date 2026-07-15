<?php

namespace App\Console\Commands;

use App\Services\BackupRequestService;
use Illuminate\Console\Command;

final class CheckBackupRequestWorker extends Command
{
    protected $signature = 'dis:check-backup-request-worker {--timeout=30}';

    protected $description = 'Check that the privileged DIS backup request worker processes requests.';

    public function handle(BackupRequestService $backupRequests): int
    {
        $timeoutOption = trim((string) $this->option('timeout'));
        if (preg_match('/^[0-9]+$/', $timeoutOption) !== 1) {
            $this->error('The probe timeout must be a whole number between 1 and 120 seconds.');

            return self::INVALID;
        }

        $timeoutSeconds = (int) $timeoutOption;
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            $this->error('The probe timeout must be between 1 and 120 seconds.');

            return self::INVALID;
        }

        $result = $backupRequests->probe($timeoutSeconds);
        $output = trim((string) ($result['output'] ?? ''));

        if ((int) ($result['exit_code'] ?? 1) !== 0) {
            $message = $output !== '' ? $output : 'Backup request worker probe failed.';
            $this->error($message);

            return self::FAILURE;
        }

        $this->info($output !== '' ? $output : 'Backup request worker is healthy.');

        return self::SUCCESS;
    }
}
