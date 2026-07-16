<?php

namespace App\Exceptions;

use RuntimeException;

final class TransientPushDeliveryException extends RuntimeException
{
    public static function forHttpStatus(int $status): self
    {
        return new self('The push provider returned a transient HTTP '.$status.' response.');
    }
}
