<?php

namespace App\Support;

final readonly class WallboardMediaProcessedAsset
{
    public function __construct(
        public string $kind,
        public string $temporaryPath,
        public string $sha256,
        public string $mimeType,
        public int $byteSize,
        public ?int $width,
        public ?int $height,
        public ?int $durationSeconds,
        public ?string $thumbnailTemporaryPath,
        public ?string $thumbnailSha256,
        public ?int $thumbnailByteSize,
        public bool $requiresVideoTranscode = false,
    ) {}
}
