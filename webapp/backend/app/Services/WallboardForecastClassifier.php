<?php

namespace App\Services;

final class WallboardForecastClassifier
{
    public const STATUS_GREEN = 'green';

    public const STATUS_ORANGE = 'orange';

    public const STATUS_RED = 'red';

    public const STATUS_UNKNOWN = 'unknown';

    /** @return array{status: string, explanation: string} */
    public function classify(string $metric, float|int|null $value, bool $stale = false): array
    {
        if ($value === null || ! is_finite((float) $value) || $stale) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'explanation' => $stale
                    ? 'De laatst bekende meting is te oud en telt daarom niet als veilig.'
                    : 'Er is geen betrouwbare actuele waarde beschikbaar.',
            ];
        }

        return match ($metric) {
            'wind_speed_kmh' => $this->maximumBands(
                (float) $value,
                $this->threshold('wind_speed_kmh', 'green_max', 20),
                $this->threshold('wind_speed_kmh', 'orange_max', 30),
                'km/u',
            ),
            'wind_gust_kmh' => $this->maximumBands(
                (float) $value,
                $this->threshold('wind_gust_kmh', 'green_max', 30),
                $this->threshold('wind_gust_kmh', 'orange_max', 45),
                'km/u',
            ),
            'precipitation_mm' => $this->maximumBands(
                (float) $value,
                $this->threshold('precipitation_mm', 'green_max', 0),
                $this->threshold('precipitation_mm', 'orange_max', 0.5),
                'mm',
            ),
            'visibility_m' => $this->minimumBands(
                (float) $value,
                $this->threshold('visibility_m', 'green_min', 5000),
                $this->threshold('visibility_m', 'orange_min', 2000),
                'm',
            ),
            'kp_index' => $this->exclusiveMaximumBands(
                (float) $value,
                $this->threshold('kp_index', 'green_max_exclusive', 4),
                $this->threshold('kp_index', 'orange_max_exclusive', 6),
            ),
            default => [
                'status' => self::STATUS_UNKNOWN,
                'explanation' => 'Voor deze waarde is geen centraal gevalideerde drempel beschikbaar.',
            ],
        };
    }

    /** @param list<array{status?: mixed}> $metrics */
    public function overall(array $metrics): string
    {
        $statuses = array_map(static fn (array $metric): string => is_string($metric['status'] ?? null)
            ? $metric['status']
            : self::STATUS_UNKNOWN, $metrics);

        if (in_array(self::STATUS_RED, $statuses, true)) {
            return self::STATUS_RED;
        }
        if (in_array(self::STATUS_ORANGE, $statuses, true)) {
            return self::STATUS_ORANGE;
        }
        if ($statuses === [] || in_array(self::STATUS_UNKNOWN, $statuses, true)) {
            return self::STATUS_UNKNOWN;
        }

        return self::STATUS_GREEN;
    }

    /** @return array{status: string, explanation: string} */
    private function maximumBands(float $value, float $greenMax, float $orangeMax, string $unit): array
    {
        if ($greenMax > $orangeMax) {
            [$greenMax, $orangeMax] = [$orangeMax, $greenMax];
        }
        $status = $value <= $greenMax
            ? self::STATUS_GREEN
            : ($value <= $orangeMax ? self::STATUS_ORANGE : self::STATUS_RED);

        return [
            'status' => $status,
            'explanation' => "Groen t/m {$greenMax} {$unit}, oranje t/m {$orangeMax} {$unit}, daarboven rood.",
        ];
    }

    /** @return array{status: string, explanation: string} */
    private function minimumBands(float $value, float $greenMin, float $orangeMin, string $unit): array
    {
        if ($orangeMin > $greenMin) {
            [$orangeMin, $greenMin] = [$greenMin, $orangeMin];
        }
        $status = $value >= $greenMin
            ? self::STATUS_GREEN
            : ($value >= $orangeMin ? self::STATUS_ORANGE : self::STATUS_RED);

        return [
            'status' => $status,
            'explanation' => "Groen vanaf {$greenMin} {$unit}, oranje vanaf {$orangeMin} {$unit}, daaronder rood.",
        ];
    }

    /** @return array{status: string, explanation: string} */
    private function exclusiveMaximumBands(float $value, float $greenBelow, float $orangeBelow): array
    {
        if ($greenBelow > $orangeBelow) {
            [$greenBelow, $orangeBelow] = [$orangeBelow, $greenBelow];
        }
        $status = $value < $greenBelow
            ? self::STATUS_GREEN
            : ($value < $orangeBelow ? self::STATUS_ORANGE : self::STATUS_RED);

        return [
            'status' => $status,
            'explanation' => "Groen onder Kp {$greenBelow}, oranje tot onder Kp {$orangeBelow}, vanaf Kp {$orangeBelow} rood.",
        ];
    }

    private function threshold(string $metric, string $band, float $fallback): float
    {
        $value = config("dis.wallboards.uav_forecast.thresholds.{$metric}.{$band}", $fallback);

        return is_numeric($value) && is_finite((float) $value) ? (float) $value : $fallback;
    }
}
