<?php

namespace App\Support;

final readonly class WallboardMediaNormalizedImage
{
    public function __construct(
        public string $temporaryPath,
        public string $sha256,
        public int $byteSize,
        public int $width,
        public int $height,
        public string $thumbnailTemporaryPath,
        public string $thumbnailSha256,
        public int $thumbnailByteSize,
        public string $sourceSha256,
        public int $sourceByteSize,
        public int $sourceWidth,
        public int $sourceHeight,
    ) {}
}
