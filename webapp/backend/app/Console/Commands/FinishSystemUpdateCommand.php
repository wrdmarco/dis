<?php

namespace App\Console\Commands;

use App\Services\SystemUpdateStatusService;
use Illuminate\Console\Command;

final class FinishSystemUpdateCommand extends Command
{
    protected $signature = 'dis:finish-update {exitCode : Update process exit code}';

    protected $description = 'Finalize the DIS updater status after the detached runner exits.';

    public function handle(SystemUpdateStatusService $status): int
    {
        $exitCode = (int) $this->argument('exitCode');
        $status->append('Update achtergrondproces afgerond met exit code '.$exitCode.'.');
        $status->finish($exitCode);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
