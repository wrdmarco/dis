<?php

namespace App\Console\Commands;

use App\Models\OsrmOperation;
use App\Services\OsrmOperationService;
use Illuminate\Console\Command;
use Throwable;

final class FinishOsrmOperationCommand extends Command
{
    protected $signature = 'dis:osrm-operation:finish {operationId} {exitCode}';

    protected $description = 'Finalize a privileged OSRM operation from its root-owned result snapshot.';

    public function handle(OsrmOperationService $operations): int
    {
        try {
            $exitCode = filter_var($this->argument('exitCode'), FILTER_VALIDATE_INT);
            if (! is_int($exitCode) || $exitCode < 0 || $exitCode > 255) {
                throw new \InvalidArgumentException('Invalid OSRM operation exit code.');
            }
            $operation = $operations->finish($this->operation(), $exitCode);
            $this->line((string) json_encode(
                $operations->summary($operation),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('OSRM operation could not be finalized.');

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
