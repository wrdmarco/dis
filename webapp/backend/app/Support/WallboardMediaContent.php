<?php

namespace App\Support;

final readonly class WallboardMediaContent
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $contentType,
        public int $byteSize,
        public string $etag,
    ) {}
}
