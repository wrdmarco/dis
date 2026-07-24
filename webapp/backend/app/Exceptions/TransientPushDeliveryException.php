<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class TransientPushDeliveryException extends RuntimeException
{
    public static function forHttpStatus(int $status): self
    {
        return new self('The push provider returned a transient HTTP '.$status.' response.');
    }

    public static function forDeviceStateLock(?Throwable $previous = null): self
    {
        return new self(
            'The push device state is temporarily locked. Retry the operation.',
            0,
            $previous,
        );
    }

    public static function forDeviceIdentityChange(): self
    {
        return new self('The push device identity changed while acquiring its state lock. Retry the operation.');
    }
}
