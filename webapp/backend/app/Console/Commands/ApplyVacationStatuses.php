<?php

namespace App\Console\Commands;

use App\Services\VacationService;
use Illuminate\Console\Command;

final class ApplyVacationStatuses extends Command
{
    protected $signature = 'dis:apply-vacation-statuses';

    protected $description = 'Apply scheduled user vacations to availability statuses.';

    public function handle(VacationService $service): int
    {
        $result = $service->applyDueVacations();
        $this->info('Vacation statuses checked. Activated: '.$result['activated'].', completed: '.$result['completed'].'.');

        return self::SUCCESS;
    }
}
