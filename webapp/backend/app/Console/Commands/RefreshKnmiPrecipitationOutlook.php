<?php

namespace App\Console\Commands;

use App\Exceptions\WeatherDatasetOperationConflictException;
use App\Exceptions\WeatherDatasetOperationStartException;
use App\Services\KnmiPrecipitationConfiguration;
use App\Services\WeatherDatasetOperationService;
use Illuminate\Console\Command;

final class RefreshKnmiPrecipitationOutlook extends Command
{
    protected $signature = 'dis:refresh-knmi-precipitation-outlook';

    protected $description = 'Queue a tracked refresh of local KNMI radar and optional precipitation probability data';

    public function handle(
        KnmiPrecipitationConfiguration $configuration,
        WeatherDatasetOperationService $operations,
    ): int {
        if ($configuration->apiKey() === null) {
            $this->components->info('KNMI precipitation refresh skipped: Open Data API key is not configured.');

            return self::SUCCESS;
        }
        try {
            $operations->request(WeatherDatasetOperationService::RADAR, scheduled: true);
        } catch (WeatherDatasetOperationConflictException) {
            $this->components->info('KNMI precipitation refresh skipped: an update is already active.');
        } catch (WeatherDatasetOperationStartException) {
            $this->components->error('KNMI precipitation refresh failed: the update queue is unavailable.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
