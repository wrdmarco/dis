<?php

namespace App\Support;

final readonly class WallboardMediaProcessedImage
{
    public function __construct(
        public string $temporaryPath,
        public string $sha256,
        public int $byteSize,
        public int $width,
        public int $height,
    ) {}
}
