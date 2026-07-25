<?php

namespace App\Console\Commands;

use App\Services\AvailabilityScheduleService;
use Illuminate\Console\Command;

final class ApplyVacationStatuses extends Command
{
    protected $signature = 'dis:apply-vacation-statuses';

    protected $description = 'Deprecated alias: apply the authoritative availability schedule to user statuses.';

    public function handle(AvailabilityScheduleService $service): int
    {
        $this->warn('Deprecated command alias; use dis:apply-availability-schedule-statuses.');
        $result = $service->syncCurrentStatuses();
        $this->info('Availability schedule statuses checked. Users: '.$result['checked'].', updated: '.$result['updated'].'.');

        return self::SUCCESS;
    }
}
