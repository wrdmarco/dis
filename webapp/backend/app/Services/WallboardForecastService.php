<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WallboardForecastService
{
    private const WEATHER_URL = 'https://api.open-meteo.com/v1/forecast';

    private const KP_URL = 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json';

    public function __construct(private readonly WallboardForecastClassifier $classifier) {}

    /** @param array<string, mixed> $configuration
     * @return array<string, array<string, mixed>>
     */
    public function pages(array $configuration): array
    {
        $pages = collect((array) ($configuration['pages'] ?? []))
            ->filter(fn (mixed $page): bool => is_array($page) && ($page['type'] ?? null) === 'uav_forecast');
        if ($pages->isEmpty()) {
            return [];
        }

        $kp = $this->kpReading();

        return $pages->mapWithKeys(function (array $page) use ($kp): array {
            $options = (array) ($page['options'] ?? []);
            $latitude = (float) ($options['latitude'] ?? 0);
            $longitude = (float) ($options['longitude'] ?? 0);
            $weather = $this->weatherReading($latitude, $longitude);
            $metrics = [
                $this->metric('wind_speed_kmh', 'Wind', $weather['wind_speed_kmh'] ?? null, 'km/u', $weather, 1),
                $this->metric('wind_gust_kmh', 'Windstoten', $weather['wind_gust_kmh'] ?? null, 'km/u', $weather, 1),
                $this->metric('precipitation_mm', 'Neerslag', $weather['precipitation_mm'] ?? null, 'mm', $weather, 1),
                $this->metric('visibility_m', 'Zicht', $weather['visibility_m'] ?? null, 'm', $weather, 0),
                $this->metric('kp_index', 'Geomagnetische activiteit', $kp['value'] ?? null, 'Kp', $kp, 2),
                [
                    'key' => 'gnss_satellites',
                    'label' => 'GNSS-satellieten',
                    'value' => null,
                    'unit' => null,
                    'status' => WallboardForecastClassifier::STATUS_UNKNOWN,
                    'stale' => false,
                    'source' => ['name' => 'Niet beschikbaar', 'url' => null],
                    'measured_at' => null,
                    'explanation' => 'Geen betrouwbare locatie- en tijdafhankelijke satelliettelling is uit de aanwezige bronnen af te leiden.',
                ],
            ];

            return [(string) $page['id'] => [
                'location' => [
                    'label' => (string) ($options['location_label'] ?? ''),
                    'latitude' => round($latitude, 7),
                    'longitude' => round($longitude, 7),
                ],
                'overall_status' => $this->classifier->overall($metrics),
                'generated_at' => now()->toIso8601String(),
                'metrics' => $metrics,
                'disclaimer' => 'Indicatief vliegadvies. Toestellimieten, missieprofiel, lokale weerswaarneming, luchtruimregels en gezaghebbende operationele beoordeling gaan altijd voor.',
            ]];
        })->all();
    }

    /** @param array<string, mixed> $reading
     * @return array<string, mixed>
     */
    private function metric(
        string $key,
        string $label,
        mixed $rawValue,
        ?string $unit,
        array $reading,
        int $precision,
    ): array {
        $value = is_numeric($rawValue) && is_finite((float) $rawValue)
            ? round((float) $rawValue, $precision)
            : null;
        $stale = (bool) ($reading['stale'] ?? false);
        $classification = $this->classifier->classify($key, $value, $stale);

        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'status' => $classification['status'],
            'stale' => $stale,
            'source' => $reading['source'] ?? ['name' => 'Onbekend', 'url' => null],
            'measured_at' => $reading['measured_at'] ?? null,
            'explanation' => $classification['explanation'],
        ];
    }

    /** @return array<string, mixed> */
    private function weatherReading(float $latitude, float $longitude): array
    {
        $cacheKey = 'wallboard:uav-forecast:weather:'.sha1(sprintf('%.5f,%.5f', $latitude, $longitude));

        return $this->cachedReading($cacheKey, function () use ($latitude, $longitude): array {
            $response = Http::connectTimeout($this->positiveConfig('connect_timeout_seconds', 2))
                ->timeout($this->positiveConfig('timeout_seconds', 5))
                ->acceptJson()
                ->get(self::WEATHER_URL, [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'current' => 'wind_speed_10m,wind_gusts_10m,precipitation,visibility',
                    'timezone' => 'UTC',
                    'forecast_days' => 1,
                ]);
            if (! $response->successful() || strlen($response->body()) > 65536) {
                throw new \RuntimeException('Weather provider unavailable.');
            }
            $current = $response->json('current');
            if (! is_array($current) || ! is_string($current['time'] ?? null)) {
                throw new \UnexpectedValueException('Weather response invalid.');
            }
            $measuredAt = $this->timestamp($current['time']);

            return [
                'wind_speed_kmh' => $this->boundedNumber($current['wind_speed_10m'] ?? null, 0, 500),
                'wind_gust_kmh' => $this->boundedNumber($current['wind_gusts_10m'] ?? null, 0, 500),
                'precipitation_mm' => $this->boundedNumber($current['precipitation'] ?? null, 0, 500),
                'visibility_m' => $this->boundedNumber($current['visibility'] ?? null, 0, 100000),
                'measured_at' => $measuredAt->toIso8601String(),
                'stale' => $this->isStale($measuredAt, $this->positiveConfig('weather_stale_seconds', 1800)),
                'source' => ['name' => 'Open-Meteo', 'url' => 'https://open-meteo.com/en/docs'],
            ];
        });
    }

    /** @return array<string, mixed> */
    private function kpReading(): array
    {
        return $this->cachedReading('wallboard:uav-forecast:kp', function (): array {
            $response = Http::connectTimeout($this->positiveConfig('connect_timeout_seconds', 2))
                ->timeout($this->positiveConfig('timeout_seconds', 5))
                ->acceptJson()
                ->get(self::KP_URL);
            if (! $response->successful() || strlen($response->body()) > 262144) {
                throw new \RuntimeException('Kp provider unavailable.');
            }
            $payload = $response->json();
            if (! is_array($payload)) {
                throw new \UnexpectedValueException('Kp response invalid.');
            }
            $latest = null;
            foreach ($payload as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $time = $row['time_tag'] ?? $row[0] ?? null;
                $value = $row['Kp'] ?? $row[1] ?? null;
                if (! is_string($time) || ! is_numeric($value)) {
                    continue;
                }
                try {
                    $measuredAt = $this->timestamp($time);
                } catch (Throwable) {
                    continue;
                }
                if ($measuredAt->greaterThan(now()->addMinutes(10)) || (float) $value < 0 || (float) $value > 9) {
                    continue;
                }
                if ($latest === null || $measuredAt->greaterThan($latest['time'])) {
                    $latest = ['time' => $measuredAt, 'value' => (float) $value];
                }
            }
            if ($latest === null) {
                throw new \UnexpectedValueException('Kp response contains no valid observations.');
            }

            return [
                'value' => $latest['value'],
                'measured_at' => $latest['time']->toIso8601String(),
                'stale' => $this->isStale($latest['time'], $this->positiveConfig('kp_stale_seconds', 14400)),
                'source' => ['name' => 'NOAA SWPC', 'url' => 'https://www.swpc.noaa.gov/products/planetary-k-index'],
            ];
        });
    }

    /** @param callable(): array<string, mixed> $loader
     * @return array<string, mixed>
     */
    private function cachedReading(string $key, callable $loader): array
    {
        $fresh = Cache::get($key.':fresh');
        if (is_array($fresh)) {
            return $fresh;
        }

        try {
            $reading = $loader();
            Cache::put($key.':fresh', $reading, $this->positiveConfig('cache_seconds', 300));
            Cache::put($key.':last-good', $reading, $this->positiveConfig('last_good_cache_seconds', 21600));

            return $reading;
        } catch (Throwable) {
            $lastGood = Cache::get($key.':last-good');
            if (is_array($lastGood)) {
                $lastGood['stale'] = true;

                return $lastGood;
            }

            return [
                'stale' => false,
                'source' => ['name' => 'Onbekend', 'url' => null],
                'measured_at' => null,
            ];
        }
    }

    private function timestamp(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    private function isStale(CarbonImmutable $measuredAt, int $maximumAgeSeconds): bool
    {
        return $measuredAt->greaterThan(now()->addMinutes(10))
            || $measuredAt->lessThan(now()->subSeconds($maximumAgeSeconds));
    }

    private function boundedNumber(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }
        $number = (float) $value;

        return $number >= $minimum && $number <= $maximum ? $number : null;
    }

    private function positiveConfig(string $key, int $fallback): int
    {
        $value = config("dis.wallboards.uav_forecast.{$key}", $fallback);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}
