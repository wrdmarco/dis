<?php

namespace App\Exceptions;

use App\Models\MobilePairingCode;
use RuntimeException;

final class StoreReviewPairingBlockedException extends RuntimeException
{
    public function __construct(
        public readonly MobilePairingCode $pairing,
        public readonly string $reasonCode,
        public readonly string $validationField,
        string $message,
    ) {
        parent::__construct($message);
    }
}
