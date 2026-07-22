<?php

namespace App\Support;

final readonly class OperationalRadarContent
{
    public function __construct(
        public string $path,
        public int $byteSize,
        public string $sha256,
    ) {}

    public function etag(): string
    {
        return '"'.$this->sha256.'"';
    }
}
