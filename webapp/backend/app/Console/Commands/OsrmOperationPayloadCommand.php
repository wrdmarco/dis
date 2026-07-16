<?php

namespace App\Console\Commands;

use App\Models\OsrmOperation;
use App\Services\OsrmOperationService;
use Illuminate\Console\Command;
use Throwable;

final class OsrmOperationPayloadCommand extends Command
{
    protected $signature = 'dis:osrm-operation:payload {operationId}';

    protected $description = 'Return the immutable payload for a claimed privileged OSRM operation.';

    public function handle(OsrmOperationService $operations): int
    {
        try {
            $operation = $this->operation();
            $this->line((string) json_encode(
                $operations->payload($operation),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('OSRM operation payload is unavailable.');

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
