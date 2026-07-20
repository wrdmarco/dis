<?php

namespace App\Console\Commands;

use App\Jobs\RefreshKnmiPrecipitationOutlookSnapshot;
use App\Services\KnmiPrecipitationConfiguration;
use Illuminate\Console\Command;

final class RefreshKnmiPrecipitationOutlook extends Command
{
    protected $signature = 'dis:refresh-knmi-precipitation-outlook';

    protected $description = 'Queue an atomic refresh of the local KNMI precipitation file pair';

    public function handle(KnmiPrecipitationConfiguration $configuration): int
    {
        if ($configuration->apiKey() === null) {
            $this->components->info('KNMI precipitation refresh skipped: Open Data API key is not configured.');

            return self::SUCCESS;
        }
        RefreshKnmiPrecipitationOutlookSnapshot::dispatch();

        return self::SUCCESS;
    }
}
