<?php

namespace App\Exceptions;

use App\Models\OsrmOperation;
use RuntimeException;

final class OsrmRequestPublicationException extends RuntimeException
{
    public function __construct(public readonly OsrmOperation $operation)
    {
        parent::__construct('The protected OSRM request could not be published.');
    }
}
