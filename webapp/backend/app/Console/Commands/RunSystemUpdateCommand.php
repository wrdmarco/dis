<?php

namespace App\Console\Commands;

use App\Jobs\RunSystemUpdate;
use App\Services\SystemUpdateStatusService;
use Illuminate\Console\Command;
use Throwable;

final class RunSystemUpdateCommand extends Command
{
    protected $signature = 'dis:run-update {--system : Include Ubuntu package updates}';

    protected $description = 'Run the DIS updater outside the queue worker.';

    public function handle(SystemUpdateStatusService $status): int
    {
        try {
            (new RunSystemUpdate((bool) $this->option('system')))->handle($status);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $status->append('Updateproces afgebroken: '.$exception->getMessage());
            $status->finish(1);

            return self::FAILURE;
        }
    }
}
