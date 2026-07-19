<?php

namespace App\Console\Commands;

use App\Services\SystemUpdateDurationEstimator;
use Illuminate\Console\Command;

final class EstimateSystemUpdateDurationCommand extends Command
{
    protected $signature = 'dis:estimate-update-duration {--system : Include Ubuntu package updates}';

    protected $description = 'Return the bounded estimated DIS update duration in seconds.';

    public function handle(SystemUpdateDurationEstimator $estimator): int
    {
        $estimate = $estimator->estimate((bool) $this->option('system'));
        $this->line((string) $estimate['duration_seconds']);

        return self::SUCCESS;
    }
}
