<?php

namespace App\Support;

final readonly class OperationalRadarContent
{
    public function __construct(
        public string $body,
        public int $byteSize,
        public string $sha256,
    ) {}

    public static function fromBody(string $body): self
    {
        return new self(
            body: $body,
            byteSize: strlen($body),
            sha256: hash('sha256', $body),
        );
    }

    public function etag(): string
    {
        return '"'.$this->sha256.'"';
    }
}
