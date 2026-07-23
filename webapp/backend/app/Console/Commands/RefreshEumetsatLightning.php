<?php

namespace App\Console\Commands;

use App\Exceptions\WeatherDatasetOperationConflictException;
use App\Exceptions\WeatherDatasetOperationStartException;
use App\Services\WeatherDatasetOperationService;
use Illuminate\Console\Command;

final class RefreshEumetsatLightning extends Command
{
    protected $signature = 'dis:refresh-eumetsat-lightning';

    protected $description = 'Queue a free EUMETView MTG Lightning Imager atlas refresh';

    public function handle(WeatherDatasetOperationService $operations): int
    {
        try {
            $operations->request(WeatherDatasetOperationService::EUMETSAT_LIGHTNING, scheduled: true);
        } catch (WeatherDatasetOperationConflictException) {
            $this->components->info('EUMETSAT lightning refresh skipped: an update is already active.');
        } catch (WeatherDatasetOperationStartException) {
            $this->components->error('EUMETSAT lightning refresh failed: the update queue is unavailable.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
