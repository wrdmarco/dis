<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class KnmiForecastImportException extends RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        string $internalMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($internalMessage, 0, $previous);
    }
}
