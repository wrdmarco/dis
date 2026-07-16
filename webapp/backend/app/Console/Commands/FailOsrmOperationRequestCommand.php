<?php

namespace App\Console\Commands;

use App\Services\OsrmOperationService;
use Illuminate\Console\Command;
use Throwable;

final class FailOsrmOperationRequestCommand extends Command
{
    protected $signature = 'dis:osrm-operation:fail-request
        {requestId : 32-character request filename id}
        {reason : rejected, expired or abandoned}';

    protected $description = 'Fail and release an active OSRM operation by its protected broker request id.';

    public function handle(OsrmOperationService $operations): int
    {
        try {
            $requestId = (string) $this->argument('requestId');
            $reason = (string) $this->argument('reason');
            $operation = $operations->failByRequestId($requestId, $reason);
            $this->line((string) json_encode(
                $operations->summary($operation),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('OSRM request failure could not be recorded.');

            return self::FAILURE;
        }
    }
}
