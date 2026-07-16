<?php

namespace App\DTO\Routing;

use InvalidArgumentException;

final readonly class RoutePoint
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if (! is_finite($this->latitude) || $this->latitude < -90 || $this->latitude > 90) {
            throw new InvalidArgumentException('Latitude must be a finite value between -90 and 90.');
        }

        if (! is_finite($this->longitude) || $this->longitude < -180 || $this->longitude > 180) {
            throw new InvalidArgumentException('Longitude must be a finite value between -180 and 180.');
        }
    }

    public function fingerprint(): string
    {
        return sprintf('%.6F,%.6F', $this->latitude, $this->longitude);
    }

    public function osrmCoordinate(): string
    {
        return sprintf('%.6F,%.6F', $this->longitude, $this->latitude);
    }
}
