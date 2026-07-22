<?php

namespace App\Contracts;

use App\Support\OperationalRadarContent;

interface OperationalRadarProvider
{
    /** @return array<string, mixed> */
    public function metadata(): array;

    public function file(string $kind, string $snapshotId): ?OperationalRadarContent;
}
