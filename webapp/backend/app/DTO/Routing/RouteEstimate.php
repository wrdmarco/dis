<?php

namespace App\DTO\Routing;

use InvalidArgumentException;

final readonly class RouteEstimate
{
    private function __construct(
        public ?int $duration,
        public ?int $distance,
        public RouteSource $source,
    ) {
        if (($this->duration !== null && $this->duration < 0) || ($this->distance !== null && $this->distance < 0)) {
            throw new InvalidArgumentException('Route duration and distance cannot be negative.');
        }

        if ($this->source === RouteSource::Unknown && ($this->duration !== null || $this->distance !== null)) {
            throw new InvalidArgumentException('An unknown route cannot contain duration or distance.');
        }

        if ($this->source !== RouteSource::Unknown && ($this->duration === null || $this->distance === null)) {
            throw new InvalidArgumentException('A known route requires both duration and distance.');
        }
    }

    public static function navigation(int $duration, int $distance): self
    {
        return new self($duration, $distance, RouteSource::Navigation);
    }

    public static function fallback(int $duration, int $distance): self
    {
        return new self($duration, $distance, RouteSource::Fallback);
    }

    public static function unknown(): self
    {
        return new self(null, null, RouteSource::Unknown);
    }

    /**
     * Duration is expressed in seconds and distance in metres.
     *
     * @return array{duration: int|null, distance: int|null, source: string}
     */
    public function toArray(): array
    {
        return [
            'duration' => $this->duration,
            'distance' => $this->distance,
            'source' => $this->source->value,
        ];
    }
}
