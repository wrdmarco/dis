<?php

namespace App\Console\Commands;

use App\Services\AvailabilityScheduleService;
use Illuminate\Console\Command;

final class ApplyAvailabilityScheduleStatuses extends Command
{
    protected $signature = 'dis:apply-availability-schedule-statuses';

    protected $description = 'Apply availability schedule changes to current user statuses.';

    public function handle(AvailabilityScheduleService $service): int
    {
        $result = $service->syncCurrentStatuses();
        $this->info('Availability schedule statuses checked. Users: '.$result['checked'].', updated: '.$result['updated'].'.');

        return self::SUCCESS;
    }
}
