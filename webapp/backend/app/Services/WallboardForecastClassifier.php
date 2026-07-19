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
            'weather_code' => $this->weatherCode((int) $value),
            'temperature_c' => $this->rangeBands(
                (float) $value,
                $this->threshold('temperature_c', 'green_min', 0),
                $this->threshold('temperature_c', 'green_max', 35),
                $this->threshold('temperature_c', 'orange_min', -10),
                $this->threshold('temperature_c', 'orange_max', 45),
                '°C',
            ),
            // The supplied classification value is the temperature/dew-point
            // spread, while the displayed value remains the dew point itself.
            'dew_point_c' => $this->minimumBands(
                (float) $value,
                $this->threshold('dew_point_c', 'green_spread_min', 3),
                $this->threshold('dew_point_c', 'orange_spread_min', 1.5),
                '°C temperatuur-dauwpuntverschil',
            ),
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
            'precipitation_probability_pct' => $this->maximumBands(
                (float) $value,
                $this->threshold('precipitation_probability_pct', 'green_max', 20),
                $this->threshold('precipitation_probability_pct', 'orange_max', 50),
                '%',
            ),
            'cloud_cover_pct' => $this->maximumBands(
                (float) $value,
                $this->threshold('cloud_cover_pct', 'green_max', 50),
                $this->threshold('cloud_cover_pct', 'orange_max', 85),
                '%',
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
            'wind_direction_degrees' => [
                'status' => self::STATUS_GREEN,
                'explanation' => 'Actuele windrichting; dit is informatief en heeft zonder windsnelheid geen zelfstandige veiligheidsdrempel.',
            ],
            default => [
                'status' => self::STATUS_UNKNOWN,
                'explanation' => 'Voor deze waarde is geen centraal gevalideerde drempel beschikbaar.',
            ],
        };
    }

    public function weatherCodeRisk(int $code): int
    {
        if (in_array($code, [0, 1, 2, 3], true)) {
            return 0;
        }
        if (in_array($code, [45, 48, 51, 53, 55, 61, 63, 71, 73, 77, 80, 85], true)) {
            return 1;
        }
        if (in_array($code, [56, 57, 65, 66, 67, 75, 81, 82, 86, 95, 96, 99], true)) {
            return 2;
        }

        return 3;
    }

    public function weatherCodeLabel(int $code): string
    {
        return match ($code) {
            0 => 'Onbewolkt',
            1 => 'Overwegend helder',
            2 => 'Gedeeltelijk bewolkt',
            3 => 'Bewolkt',
            45, 48 => 'Mist',
            51, 53, 55 => 'Motregen',
            56, 57 => 'IJzelende motregen',
            61, 63, 65 => 'Regen',
            66, 67 => 'IJzel',
            71, 73, 75 => 'Sneeuw',
            77 => 'Sneeuwkorrels',
            80, 81, 82 => 'Regenbuien',
            85, 86 => 'Sneeuwbuien',
            95, 96, 99 => 'Onweer',
            default => 'Onbekende weerscode',
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
    private function rangeBands(
        float $value,
        float $greenMin,
        float $greenMax,
        float $orangeMin,
        float $orangeMax,
        string $unit,
    ): array {
        if ($greenMin > $greenMax) {
            [$greenMin, $greenMax] = [$greenMax, $greenMin];
        }
        if ($orangeMin > $orangeMax) {
            [$orangeMin, $orangeMax] = [$orangeMax, $orangeMin];
        }
        $status = $value >= $greenMin && $value <= $greenMax
            ? self::STATUS_GREEN
            : ($value >= $orangeMin && $value <= $orangeMax ? self::STATUS_ORANGE : self::STATUS_RED);

        return [
            'status' => $status,
            'explanation' => "Groen tussen {$greenMin} en {$greenMax} {$unit}, oranje tussen {$orangeMin} en {$orangeMax} {$unit}, daarbuiten rood.",
        ];
    }

    /** @return array{status: string, explanation: string} */
    private function weatherCode(int $code): array
    {
        $risk = $this->weatherCodeRisk($code);

        return [
            'status' => match ($risk) {
                0 => self::STATUS_GREEN,
                1 => self::STATUS_ORANGE,
                2 => self::STATUS_RED,
                default => self::STATUS_UNKNOWN,
            },
            'explanation' => $risk === 3
                ? 'De WMO-weerscode wordt niet herkend en telt daarom niet als veilig.'
                : 'Centrale indicatieve classificatie van de actuele WMO-weerscode: '.$this->weatherCodeLabel($code).'.',
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
