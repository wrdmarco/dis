<?php

namespace App\DTO;

final readonly class SpeechCacheEntryMetadata
{
    public function __construct(
        public string $text,
        public string $locale,
        public string $modelCatalogKey,
        public string $modelRevision,
        public ?string $voiceDesignRevision,
        public string $audioRecipeRevision,
        public float $speed,
    ) {}
}
