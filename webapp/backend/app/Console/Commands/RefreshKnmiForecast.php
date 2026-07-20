<?php

namespace App\Console\Commands;

use App\Exceptions\KnmiForecastOperationConflictException;
use App\Services\KnmiForecastOperationService;
use Illuminate\Console\Command;

final class RefreshKnmiForecast extends Command
{
    protected $signature = 'dis:refresh-knmi-forecast';

    protected $description = 'Queue a central KNMI HARMONIE P1 forecast refresh';

    public function handle(KnmiForecastOperationService $operations): int
    {
        try {
            $operation = $operations->requestRefresh(scheduled: true);
        } catch (KnmiForecastOperationConflictException $exception) {
            $this->components->info($exception->getMessage());

            return self::SUCCESS;
        }
        $this->components->info('KNMI-update toegevoegd aan de wachtrij: '.$operation->id);

        return self::SUCCESS;
    }
}
