<?php

namespace App\Support;

final readonly class WallboardMediaProcessedThumbnail
{
    public function __construct(
        public string $temporaryPath,
        public string $sha256,
        public int $byteSize,
        public string $sourceSha256,
        public int $sourceByteSize,
        public int $sourceWidth,
        public int $sourceHeight,
    ) {}
}
