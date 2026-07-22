<?php

namespace App\Services;

use RuntimeException;
use Throwable;

final class EumetsatLightningImportException extends RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
