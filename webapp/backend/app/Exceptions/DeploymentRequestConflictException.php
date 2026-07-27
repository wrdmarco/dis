<?php

namespace App\Exceptions;

use RuntimeException;

final class DeploymentRequestConflictException extends RuntimeException
{
    /** @param array<string, mixed> $current */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $current,
    ) {
        parent::__construct($message);
    }
}
