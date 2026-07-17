<?php

namespace App\DTO\Routing;

use InvalidArgumentException;

final readonly class RouteGeometry
{
    private const MAX_COORDINATES = 20_000;

    /**
     * @param  list<array{0: float, 1: float}>  $coordinates
     */
    private function __construct(
        public int $duration,
        public int $distance,
        public array $coordinates,
    ) {}

    /**
     * @param  array<int, mixed>  $coordinates
     */
    public static function navigation(int $duration, int $distance, array $coordinates): self
    {
        if ($duration < 0 || $distance < 0) {
            throw new InvalidArgumentException('Route duration and distance cannot be negative.');
        }

        if (count($coordinates) < 2 || count($coordinates) > self::MAX_COORDINATES) {
            throw new InvalidArgumentException('A route geometry must contain a bounded LineString.');
        }

        $normalized = [];
        foreach ($coordinates as $coordinate) {
            if (! is_array($coordinate) || count($coordinate) !== 2
                || ! is_numeric($coordinate[0] ?? null) || ! is_numeric($coordinate[1] ?? null)) {
                throw new InvalidArgumentException('A route geometry contains an invalid coordinate.');
            }

            $longitude = (float) $coordinate[0];
            $latitude = (float) $coordinate[1];
            if (! is_finite($longitude) || $longitude < -180 || $longitude > 180
                || ! is_finite($latitude) || $latitude < -90 || $latitude > 90) {
                throw new InvalidArgumentException('A route geometry contains an out-of-range coordinate.');
            }

            $normalized[] = [$longitude, $latitude];
        }

        return new self($duration, $distance, $normalized);
    }

    /**
     * @return array{
     *     source: string,
     *     duration_seconds: int,
     *     distance_meters: int,
     *     geometry: array{type: string, coordinates: list<array{0: float, 1: float}>}
     * }
     */
    public function toArray(): array
    {
        return [
            'source' => RouteSource::Navigation->value,
            'duration_seconds' => $this->duration,
            'distance_meters' => $this->distance,
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $this->coordinates,
            ],
        ];
    }
}
