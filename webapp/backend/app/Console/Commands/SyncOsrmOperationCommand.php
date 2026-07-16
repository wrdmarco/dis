<?php

namespace App\Console\Commands;

use App\Models\OsrmOperation;
use App\Services\OsrmOperationService;
use Illuminate\Console\Command;
use Throwable;

final class SyncOsrmOperationCommand extends Command
{
    protected $signature = 'dis:osrm-operation:sync {operationId}';

    protected $description = 'Synchronize and broadcast a root-owned OSRM operation status snapshot.';

    public function handle(OsrmOperationService $operations): int
    {
        try {
            $operation = $operations->sync($this->operation());
            $this->line((string) json_encode(
                $operations->summary($operation),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('OSRM operation status could not be synchronized.');

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
