<?php

namespace App\Console\Commands;

use App\Services\SystemUpdateStatusService;
use Illuminate\Console\Command;

final class FinishSystemUpdateCommand extends Command
{
    protected $signature = 'dis:finish-update
        {exitCode : Update process exit code}
        {durationSeconds? : Measured end-to-end runner duration}
        {--system : The completed run included Ubuntu package updates}';

    protected $description = 'Finalize the DIS updater status after the detached runner exits.';

    public function handle(SystemUpdateStatusService $status): int
    {
        $exitCode = (int) $this->argument('exitCode');
        $durationArgument = $this->argument('durationSeconds');
        $durationSeconds = is_string($durationArgument)
            && preg_match('/^[1-9][0-9]{0,4}$/D', $durationArgument) === 1
                ? (int) $durationArgument
                : null;
        if ($durationArgument !== null && $durationSeconds === null) {
            $this->error('The measured update duration must be a positive integer.');

            return self::FAILURE;
        }

        $status->append('Update achtergrondproces afgerond met exit code '.$exitCode.'.');
        $status->finish(
            $exitCode,
            $durationSeconds,
            $durationSeconds === null ? null : (bool) $this->option('system'),
        );

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
