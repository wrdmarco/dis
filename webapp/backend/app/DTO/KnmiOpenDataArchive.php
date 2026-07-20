<?php

namespace App\DTO;

final readonly class KnmiOpenDataArchive
{
    public function __construct(
        public string $filename,
        public int $sizeBytes,
    ) {}
}
