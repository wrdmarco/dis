<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class SpeechEngineException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = 'Speech engine request failed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
