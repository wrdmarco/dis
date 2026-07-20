<?php

namespace App\DTO;

use Carbon\CarbonImmutable;

final readonly class KnmiPrecipitationRemoteFile
{
    public function __construct(
        public string $dataset,
        public string $datasetVersion,
        public string $filename,
        public int $sizeBytes,
        public CarbonImmutable $referenceTime,
    ) {}
}
