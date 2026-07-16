<?php

namespace App\Console\Commands;

use App\Models\OsrmOperation;
use App\Services\OsrmOperationService;
use Illuminate\Console\Command;
use Throwable;

final class MarkOsrmOperationRunningCommand extends Command
{
    protected $signature = 'dis:osrm-operation:mark-running {operationId}';

    protected $description = 'Mark a claimed privileged OSRM operation as running.';

    public function handle(OsrmOperationService $operations): int
    {
        try {
            $operations->markRunning($this->operation());

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('OSRM operation could not be marked as running.');

            return self::FAILURE;
        }
    }

    private function operation(): OsrmOperation
    {
        $operationId = (string) $this->argument('operationId');
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $operationId) !== 1) {
            throw new \InvalidArgumentException('Invalid OSRM operation id.');
        }

        return OsrmOperation::query()->findOrFail($operationId);
    }
}
