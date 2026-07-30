<?php

namespace App\Services;

use App\Contracts\UavWeatherForecastProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class BrightSkyDwdForecastService implements UavWeatherForecastProvider
{
    private const BASE_URL = 'https://api.brightsky.dev/weather';

    private const SOURCE_URL = 'https://brightsky.dev/';

    private const LICENSE_URL = 'https://creativecommons.org/licenses/by/4.0/';

    private const CACHE_NAMESPACE = 'wallboard:uav-forecast:bright-sky-dwd:v1';

    private const MAX_RESPONSE_BYTES = 1_048_576;

    private const MAX_LOCATIONS = 12;

    private const MAX_SOURCES = 64;

    private const MAX_DISTANCE_METRES = 150_000;

    private const REQUEST_ATTEMPTS = 2;

    private const POINT_POOL_CONCURRENCY = 4;

    private const OUTLOOK_HOURS = 3;

    /** @var list<string> */
    private const CONDITIONS = [
        'dry',
        'fog',
        'rain',
        'sleet',
        'snow',
        'hail',
        'thunderstorm',
    ];

    /** @var list<string> */
    private const ICONS = [
        'clear-day',
        'clear-night',
        'partly-cloudy-day',
        'partly-cloudy-night',
        'cloudy',
        'fog',
        'wind',
        'rain',
        'sleet',
        'snow',
        'hail',
        'thunderstorm',
    ];

    /**
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    public function forResolution(array $resolution): array
    {
        if (($resolution['complete'] ?? false) !== true) {
            return $this->unavailable(
                'De gekozen locatie kon niet volledig server-side worden bepaald.',
            );
        }

        $locations = $this->validatedLocations($resolution);
        $expected = (int) ($resolution['expected_locations'] ?? 0);
        if ($locations === null
            || $expected < 1
            || $expected > self::MAX_LOCATIONS
            || count($locations) !== $expected) {
            return $this->unavailable(
                'Het vereiste aantal DWD-modelpunten kon niet betrouwbaar worden bepaald.',
            );
        }

        $cacheKey = $this->resolutionCacheKey($locations, $expected);

        try {
            $fresh = Cache::get($cacheKey.':fresh');
            if (is_array($fresh)) {
                return $this->withCurrentStaleness($fresh);
            }

            $lock = Cache::lock($cacheKey.':lock', $this->lockSeconds(count($locations)));
            if (! $lock->get()) {
                return $this->lastGoodOrUnavailable(
                    $cacheKey,
                    $expected,
                    'De DWD-modelpunten worden al opgehaald; deze aanvraag blijft uit veiligheid onbekend.',
                );
            }

            try {
                $fresh = Cache::get($cacheKey.':fresh');
                if (is_array($fresh)) {
                    return $this->withCurrentStaleness($fresh);
                }

                $reading = $this->fetchResolution($locations, $expected);
                Cache::put(
                    $cacheKey.':fresh',
                    $reading,
                    $this->positiveConfig('cache_seconds', 900, 60, 3600),
                );
                Cache::put(
                    $cacheKey.':last-good',
                    $reading,
                    $this->positiveConfig('last_good_cache_seconds', 21600, 900, 86400),
                );

                return $this->withCurrentStaleness($reading);
            } finally {
                $lock->release();
            }
        } catch (Throwable) {
            return $this->lastGoodOrUnavailable(
                $cacheKey,
                $expected,
                'DWD MOSMIX via Bright Sky is niet bereikbaar of gaf ongeldige modeldata terug.',
            );
        }
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float}>  $locations
     * @return array<string, mixed>
     */
    private function fetchResolution(array $locations, int $expected): array
    {
        $now = CarbonImmutable::now('UTC');
        $anchor = $now->startOfHour()->addHour();
        $points = $this->fetchPoints($locations, $anchor, $now);
        if (count($points) !== $expected) {
            throw new \UnexpectedValueException('Bright Sky returned an incomplete point set.');
        }

        return $this->aggregate($points, $expected, $anchor, $now);
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float}>  $locations
     * @return list<array<string, mixed>>
     */
    private function fetchPoints(
        array $locations,
        CarbonImmutable $anchor,
        CarbonImmutable $now,
    ): array {
        $probeLocation = $locations[0];
        $probeResponse = $this->request()->get(
            self::BASE_URL,
            $this->pointQuery($probeLocation, $anchor),
        );
        $points = [
            $this->parsePointResponse(
                $probeResponse,
                $probeLocation,
                $anchor,
                $now,
            ),
        ];
        if (count($locations) === 1) {
            return $points;
        }

        $remainingLocations = array_values(array_slice($locations, 1));
        $responses = Http::pool(function (Pool $pool) use (
            $anchor,
            $remainingLocations,
        ): void {
            foreach ($remainingLocations as $index => $location) {
                $this->configureRequest($pool->as('point-'.$index))
                    ->get(self::BASE_URL, $this->pointQuery($location, $anchor));
            }
        }, min(self::POINT_POOL_CONCURRENCY, count($remainingLocations)));

        foreach ($remainingLocations as $index => $location) {
            $response = $responses['point-'.$index] ?? null;
            if ($response instanceof Throwable) {
                throw $response;
            }
            if (! $response instanceof Response) {
                throw new ConnectionException('Bright Sky point response is missing.');
            }
            $points[] = $this->parsePointResponse($response, $location, $anchor, $now);
        }

        return $points;
    }

    /**
     * @param  array{label: string, latitude: float, longitude: float}  $location
     * @return array<string, mixed>
     */
    private function pointQuery(array $location, CarbonImmutable $anchor): array
    {
        return [
            'date' => $anchor->format('Y-m-d\TH:i:s\Z'),
            'last_date' => $anchor->addHours(self::OUTLOOK_HOURS)->format('Y-m-d\TH:i:s\Z'),
            'lat' => sprintf('%.7F', $location['latitude']),
            'lon' => sprintf('%.7F', $location['longitude']),
            'max_dist' => self::MAX_DISTANCE_METRES,
            'tz' => 'UTC',
            'units' => 'dwd',
        ];
    }

    /**
     * @param  array{label: string, latitude: float, longitude: float}  $location
     * @return array<string, mixed>
     */
    private function parsePointResponse(
        Response $response,
        array $location,
        CarbonImmutable $anchor,
        CarbonImmutable $now,
    ): array {
        $this->assertJsonResponse($response);
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new \UnexpectedValueException('Bright Sky point payload is invalid.');
        }

        return $this->parsePoint($payload, $location, $anchor, $now);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{label: string, latitude: float, longitude: float}  $location
     * @return array<string, mixed>
     */
    private function parsePoint(
        array $payload,
        array $location,
        CarbonImmutable $anchor,
        CarbonImmutable $now,
    ): array {
        $sources = $this->validatedSources($payload['sources'] ?? null, $location);
        $rawWeather = $payload['weather'] ?? null;
        if ($sources === null
            || ! is_array($rawWeather)
            || ! array_is_list($rawWeather)
            || count($rawWeather) !== self::OUTLOOK_HOURS + 1) {
            throw new \UnexpectedValueException('Bright Sky forecast structure is invalid.');
        }

        $rows = [];
        foreach ($rawWeather as $rawRow) {
            if (! is_array($rawRow)) {
                throw new \UnexpectedValueException('Bright Sky forecast row is invalid.');
            }
            $row = $this->validatedWeatherRow($rawRow, $sources);
            $key = $row['timestamp']->format('Y-m-d\TH:i:s\Z');
            if (isset($rows[$key])) {
                throw new \UnexpectedValueException('Bright Sky returned a duplicate forecast hour.');
            }
            $rows[$key] = $row;
        }

        $ordered = [];
        for ($offset = 0; $offset <= self::OUTLOOK_HOURS; $offset++) {
            $step = $anchor->addHours($offset);
            $row = $rows[$step->format('Y-m-d\TH:i:s\Z')] ?? null;
            if (! is_array($row)) {
                throw new \UnexpectedValueException(
                    'Bright Sky did not return the four exact requested forecast hours.',
                );
            }
            $ordered[] = $row;
        }
        if (count($rows) !== count($ordered)) {
            throw new \UnexpectedValueException('Bright Sky returned an unexpected forecast hour.');
        }

        $current = $ordered[0];
        $nextHour = $ordered[1];
        $outlookRows = array_slice($ordered, 1, self::OUTLOOK_HOURS);
        $temperature = $current['temperature'];
        $dewPoint = $current['dew_point'];
        $sun = $this->sunTimes($anchor, $location['latitude'], $location['longitude']);
        $forecastUntil = $anchor->addHours(self::OUTLOOK_HOURS);

        $firstPrecipitation = null;
        foreach ($outlookRows as $row) {
            if ($row['precipitation'] > 0.001) {
                $firstPrecipitation = $row['timestamp']->subHour()->toIso8601String();
                break;
            }
        }

        $firstThunderstorm = null;
        foreach ($outlookRows as $row) {
            if ($this->isThunderstorm($row)) {
                $firstThunderstorm = $row['timestamp']->subHour()->toIso8601String();
                break;
            }
        }

        return [
            'provider_identifier' => 'dwd_mosmix_bright_sky',
            'structured_attribution' => 'DWD_MOSMIX',
            'processing_note' => $this->processingNote(),
            'weather_code' => $this->weatherCode($current),
            'temperature_c' => $temperature,
            'dew_point_c' => $dewPoint,
            'dew_point_spread_c' => max(0.0, $temperature - $dewPoint),
            'wind_speed_10m_kmh' => $current['wind_speed'],
            'wind_speed_100m_kmh' => null,
            'wind_speed_150m_kmh' => null,
            'wind_speed_kmh' => $current['wind_speed'],
            'wind_reference_height_agl_m' => 10,
            'wind_gust_kmh' => $current['wind_gust_speed'],
            'wind_direction_degrees' => $current['wind_direction'],
            'precipitation_probability_pct' => $nextHour['precipitation_probability'],
            'precipitation_mm' => $nextHour['precipitation'],
            'precipitation_rate_mm_h' => $nextHour['precipitation'],
            'visibility_m' => $current['visibility'],
            'cloud_cover_pct' => $current['cloud_cover'],
            'cloud_cover_low_pct' => null,
            'cloud_cover_mid_pct' => null,
            'cloud_cover_high_pct' => null,
            'cloud_base_m' => null,
            'cloud_base_sample_count' => 0,
            'cloud_base_expected_sample_count' => 0,
            'cloud_base_complete' => false,
            'cloud_base_aggregation' => null,
            'sunrise' => $sun['sunrise'],
            'sunset' => $sun['sunset'],
            'forecast_precipitation_peak_mm_h' => max(array_column($outlookRows, 'precipitation')),
            'forecast_precipitation_first_at' => $firstPrecipitation,
            'forecast_precipitation_until' => $forecastUntil->toIso8601String(),
            'forecast_precipitation_third_hour_probability_pct' => $ordered[3]['precipitation_probability'],
            'forecast_precipitation_third_hour_from' => $anchor->addHours(2)->toIso8601String(),
            'thunderstorm_expected' => $firstThunderstorm !== null,
            // Bright Sky exposes an inferred condition, not a calibrated
            // probability of thunder. Keeping this null avoids inventing one.
            'thunderstorm_probability_pct' => null,
            'thunderstorm_first_expected_at' => $firstThunderstorm,
            'thunderstorm_forecast_until' => $forecastUntil->toIso8601String(),
            // Bright Sky exposes validity hours, but not the source MOSMIX run.
            'model_run_at' => null,
            'valid_at' => $anchor->toIso8601String(),
            'measured_at' => $anchor->toIso8601String(),
            'refreshed_at' => $now->toIso8601String(),
            'stale' => false,
            'source' => $this->source(),
            'sample_count' => 1,
            'expected_sample_count' => 1,
            'complete' => true,
        ];
    }

    /**
     * @param  array{label: string, latitude: float, longitude: float}  $location
     * @return array<int, array{
     *     first_record: CarbonImmutable,
     *     last_record: CarbonImmutable
     * }>|null
     */
    private function validatedSources(mixed $rawSources, array $location): ?array
    {
        if (! is_array($rawSources)
            || ! array_is_list($rawSources)
            || count($rawSources) < 1
            || count($rawSources) > self::MAX_SOURCES) {
            return null;
        }

        $sources = [];
        foreach ($rawSources as $rawSource) {
            if (! is_array($rawSource)
                || ! is_int($rawSource['id'] ?? null)
                || ($rawSource['id'] ?? -1) < 0
                || ($rawSource['observation_type'] ?? null) !== 'forecast') {
                return null;
            }
            $id = $rawSource['id'];
            if (isset($sources[$id])) {
                return null;
            }

            $latitude = $this->optionalNumber($rawSource['lat'] ?? null, -90, 90);
            $longitude = $this->optionalNumber($rawSource['lon'] ?? null, -180, 180);
            $distance = $this->optionalNumber(
                $rawSource['distance'] ?? null,
                0,
                self::MAX_DISTANCE_METRES,
            );
            $firstRecord = $this->timestamp($rawSource['first_record'] ?? null);
            $lastRecord = $this->timestamp($rawSource['last_record'] ?? null);
            if ($latitude === null
                || $longitude === null
                || $distance === null
                || $firstRecord === null
                || $lastRecord === null
                || $lastRecord->lessThan($firstRecord)
                || $this->distanceMetres(
                    $location['latitude'],
                    $location['longitude'],
                    $latitude,
                    $longitude,
                ) > self::MAX_DISTANCE_METRES) {
                return null;
            }

            $sources[$id] = [
                'first_record' => $firstRecord,
                'last_record' => $lastRecord,
            ];
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $rawRow
     * @param  array<int, array{
     *     first_record: CarbonImmutable,
     *     last_record: CarbonImmutable
     * }>  $sources
     * @return array{
     *     timestamp: CarbonImmutable,
     *     precipitation: float,
     *     temperature: float,
     *     wind_direction: float,
     *     wind_speed: float,
     *     cloud_cover: float,
     *     dew_point: float,
     *     visibility: float,
     *     wind_gust_speed: float,
     *     condition: string,
     *     precipitation_probability: float,
     *     icon: string
     * }
     */
    private function validatedWeatherRow(array $rawRow, array $sources): array
    {
        $timestamp = $this->timestamp($rawRow['timestamp'] ?? null);
        $sourceId = $rawRow['source_id'] ?? null;
        if ($timestamp === null || ! is_int($sourceId) || ! isset($sources[$sourceId])) {
            throw new \UnexpectedValueException('Bright Sky forecast source is invalid.');
        }
        $this->assertSourceCoversTimestamp($sources[$sourceId], $timestamp);

        $fallbackSourceIds = $rawRow['fallback_source_ids'] ?? [];
        if (! is_array($fallbackSourceIds)
            || count($fallbackSourceIds) > 32) {
            throw new \UnexpectedValueException('Bright Sky fallback source mapping is invalid.');
        }
        foreach ($fallbackSourceIds as $field => $fallbackSourceId) {
            if (! is_string($field)
                || strlen($field) > 80
                || ! is_int($fallbackSourceId)
                || ! isset($sources[$fallbackSourceId])) {
                throw new \UnexpectedValueException('Bright Sky fallback source is invalid.');
            }
            $this->assertSourceCoversTimestamp($sources[$fallbackSourceId], $timestamp);
        }

        $temperature = $this->requiredNumber($rawRow, 'temperature', -90, 60);
        $dewPoint = $this->requiredNumber($rawRow, 'dew_point', -100, 60);
        if ($dewPoint > $temperature + 3.0) {
            throw new \UnexpectedValueException(
                'Bright Sky dew point exceeds the temperature bounds.',
            );
        }

        $condition = $rawRow['condition'] ?? null;
        $icon = $rawRow['icon'] ?? null;
        if (! is_string($condition)
            || ! in_array($condition, self::CONDITIONS, true)
            || ! is_string($icon)
            || ! in_array($icon, self::ICONS, true)
            || ! $this->conditionAndIconAgree($condition, $icon)) {
            throw new \UnexpectedValueException('Bright Sky weather condition is invalid.');
        }

        return [
            'timestamp' => $timestamp,
            'precipitation' => $this->requiredNumber($rawRow, 'precipitation', 0, 500),
            'temperature' => $temperature,
            'wind_direction' => $this->requiredNumber($rawRow, 'wind_direction', 0, 360),
            'wind_speed' => $this->requiredNumber($rawRow, 'wind_speed', 0, 500),
            'cloud_cover' => $this->requiredNumber($rawRow, 'cloud_cover', 0, 100),
            'dew_point' => $dewPoint,
            'visibility' => $this->requiredNumber($rawRow, 'visibility', 0, 200_000),
            'wind_gust_speed' => $this->requiredNumber($rawRow, 'wind_gust_speed', 0, 500),
            'condition' => $condition,
            'precipitation_probability' => $this->requiredNumber(
                $rawRow,
                'precipitation_probability',
                0,
                100,
            ),
            'icon' => $icon,
        ];
    }

    /**
     * @param  array{first_record: CarbonImmutable, last_record: CarbonImmutable}  $source
     */
    private function assertSourceCoversTimestamp(
        array $source,
        CarbonImmutable $timestamp,
    ): void {
        if ($timestamp->lessThan($source['first_record'])
            || $timestamp->greaterThan($source['last_record'])) {
            throw new \UnexpectedValueException(
                'Bright Sky forecast source does not cover the returned hour.',
            );
        }
    }

    private function conditionAndIconAgree(string $condition, string $icon): bool
    {
        return match ($condition) {
            'dry' => in_array($icon, [
                'clear-day',
                'clear-night',
                'partly-cloudy-day',
                'partly-cloudy-night',
                'cloudy',
                'wind',
            ], true),
            'fog' => $icon === 'fog',
            'rain' => $icon === 'rain',
            'sleet' => $icon === 'sleet',
            'snow' => $icon === 'snow',
            'hail' => $icon === 'hail',
            'thunderstorm' => $icon === 'thunderstorm',
            default => false,
        };
    }

    /** @param array<string, mixed> $row */
    private function weatherCode(array $row): int
    {
        return match ($row['condition']) {
            'dry' => match ($row['icon']) {
                'clear-day', 'clear-night' => 0,
                'partly-cloudy-day', 'partly-cloudy-night' => 2,
                'cloudy', 'wind' => 3,
            },
            'fog' => 45,
            'rain' => match (true) {
                $row['precipitation'] <= 0.5 => 61,
                $row['precipitation'] <= 2.5 => 63,
                default => 65,
            },
            'sleet' => 66,
            'snow' => 71,
            'hail' => 77,
            'thunderstorm' => 95,
        };
    }

    /** @param array<string, mixed> $row */
    private function isThunderstorm(array $row): bool
    {
        return $row['condition'] === 'thunderstorm'
            || $row['icon'] === 'thunderstorm';
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @return array<string, mixed>
     */
    private function aggregate(
        array $points,
        int $expected,
        CarbonImmutable $anchor,
        CarbonImmutable $now,
    ): array {
        if (count($points) !== $expected
            || collect($points)->contains(
                static fn (array $point): bool => ($point['complete'] ?? false) !== true
                    || ($point['stale'] ?? true) !== false
                    || ($point['provider_identifier'] ?? null) !== 'dwd_mosmix_bright_sky',
            )) {
            throw new \UnexpectedValueException('Bright Sky point aggregation is incomplete.');
        }

        $validAt = $anchor->toIso8601String();
        if (collect($points)->contains(
            static fn (array $point): bool => ($point['valid_at'] ?? null) !== $validAt,
        )) {
            throw new \UnexpectedValueException('Bright Sky point validity times do not match.');
        }

        $averageKeys = [
            'temperature_c',
            'dew_point_c',
            'dew_point_spread_c',
            'wind_speed_10m_kmh',
            'wind_speed_kmh',
            'wind_gust_kmh',
            'precipitation_probability_pct',
            'precipitation_mm',
            'precipitation_rate_mm_h',
            'visibility_m',
            'cloud_cover_pct',
            'forecast_precipitation_third_hour_probability_pct',
        ];
        $result = [];
        foreach ($averageKeys as $key) {
            $values = array_column($points, $key);
            if (count($values) !== $expected
                || collect($values)->contains(
                    static fn (mixed $value): bool => ! is_numeric($value)
                        || ! is_finite((float) $value),
                )) {
                throw new \UnexpectedValueException(
                    "Bright Sky point value {$key} is incomplete.",
                );
            }
            $result[$key] = array_sum($values) / $expected;
        }

        $directions = array_map(
            static fn (array $point): float => (float) $point['wind_direction_degrees'],
            $points,
        );
        $windDirection = $this->circularMean($directions);
        if ($windDirection === null) {
            throw new \UnexpectedValueException(
                'Bright Sky wind direction aggregation is indeterminate.',
            );
        }

        $weatherCodes = array_map(
            static fn (array $point): int => (int) $point['weather_code'],
            $points,
        );
        usort(
            $weatherCodes,
            fn (int $left, int $right): int => $this->weatherRisk($right)
                <=> $this->weatherRisk($left),
        );

        $sunrises = array_map(
            fn (array $point): CarbonImmutable => $this->requiredTimestamp(
                $point['sunrise'] ?? null,
            ),
            $points,
        );
        $sunsets = array_map(
            fn (array $point): CarbonImmutable => $this->requiredTimestamp(
                $point['sunset'] ?? null,
            ),
            $points,
        );
        usort($sunrises, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
        usort($sunsets, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return [
            'provider_identifier' => 'dwd_mosmix_bright_sky',
            'structured_attribution' => 'DWD_MOSMIX',
            'processing_note' => $this->processingNote(),
            'weather_code' => $weatherCodes[0],
            ...$result,
            'wind_speed_100m_kmh' => null,
            'wind_speed_150m_kmh' => null,
            'wind_reference_height_agl_m' => 10,
            'wind_direction_degrees' => $windDirection,
            'cloud_cover_low_pct' => null,
            'cloud_cover_mid_pct' => null,
            'cloud_cover_high_pct' => null,
            'cloud_base_m' => null,
            'cloud_base_sample_count' => 0,
            'cloud_base_expected_sample_count' => 0,
            'cloud_base_complete' => false,
            'cloud_base_aggregation' => null,
            'forecast_precipitation_peak_mm_h' => max(array_map(
                'floatval',
                array_column($points, 'forecast_precipitation_peak_mm_h'),
            )),
            'forecast_precipitation_first_at' => $this->earliestOptionalTimestamp(
                array_column($points, 'forecast_precipitation_first_at'),
            ),
            'forecast_precipitation_until' => $this->sharedTimestamp(
                array_column($points, 'forecast_precipitation_until'),
            ),
            'forecast_precipitation_third_hour_from' => $this->sharedTimestamp(
                array_column($points, 'forecast_precipitation_third_hour_from'),
            ),
            'thunderstorm_expected' => collect($points)->contains(
                static fn (array $point): bool => ($point['thunderstorm_expected'] ?? false) === true,
            ),
            'thunderstorm_probability_pct' => null,
            'thunderstorm_first_expected_at' => $this->earliestOptionalTimestamp(
                array_column($points, 'thunderstorm_first_expected_at'),
            ),
            'thunderstorm_forecast_until' => $this->sharedTimestamp(
                array_column($points, 'thunderstorm_forecast_until'),
            ),
            'sunrise_earliest' => $sunrises[0]->toIso8601String(),
            'sunrise_latest' => $sunrises[array_key_last($sunrises)]->toIso8601String(),
            'sunset_earliest' => $sunsets[0]->toIso8601String(),
            'sunset_latest' => $sunsets[array_key_last($sunsets)]->toIso8601String(),
            'model_run_at' => null,
            'valid_at' => $validAt,
            'measured_at' => $validAt,
            'refreshed_at' => $now->toIso8601String(),
            'stale' => false,
            'source' => $this->source($expected),
            'sample_count' => $expected,
            'expected_sample_count' => $expected,
            'complete' => true,
        ];
    }

    private function weatherRisk(int $weatherCode): int
    {
        return match ($weatherCode) {
            0 => 0,
            2 => 1,
            3 => 2,
            45 => 3,
            61 => 4,
            63 => 5,
            65 => 6,
            66 => 7,
            71 => 8,
            77 => 9,
            95 => 10,
            default => throw new \UnexpectedValueException(
                'Bright Sky weather code aggregation is invalid.',
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @return list<array{label: string, latitude: float, longitude: float}>|null
     */
    private function validatedLocations(array $resolution): ?array
    {
        $rawLocations = $resolution['locations'] ?? null;
        if (! is_array($rawLocations) || ! array_is_list($rawLocations)) {
            return null;
        }

        $locations = [];
        $coordinates = [];
        foreach ($rawLocations as $location) {
            if (! is_array($location)
                || ! is_string($location['label'] ?? null)
                || trim($location['label']) === ''
                || ! is_numeric($location['latitude'] ?? null)
                || ! is_numeric($location['longitude'] ?? null)) {
                return null;
            }
            $latitude = (float) $location['latitude'];
            $longitude = (float) $location['longitude'];
            if (! is_finite($latitude)
                || ! is_finite($longitude)
                || $latitude < 50.0
                || $latitude > 54.5
                || $longitude < 2.5
                || $longitude > 8.0) {
                return null;
            }

            $key = sprintf('%.5F,%.5F', $latitude, $longitude);
            if (isset($coordinates[$key])) {
                return null;
            }
            $coordinates[$key] = true;
            $locations[] = [
                'label' => trim($location['label']),
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $locations;
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float}>  $locations
     */
    private function resolutionCacheKey(array $locations, int $expected): string
    {
        $coordinates = array_map(
            static fn (array $location): string => sprintf(
                '%.5F,%.5F',
                $location['latitude'],
                $location['longitude'],
            ),
            $locations,
        );

        return self::CACHE_NAMESPACE.':resolution:'.hash(
            'sha256',
            implode('|', [(string) $expected, ...$coordinates]),
        );
    }

    /**
     * @return array{
     *     name: string,
     *     url: string,
     *     license: string,
     *     license_url: string,
     *     attribution: string,
     *     modified: bool,
     *     processed_by: string,
     *     processing_note: string
     * }
     */
    private function source(?int $expected = null): array
    {
        return [
            'name' => $expected === WallboardForecastLocationService::NETHERLANDS_PROVINCE_COUNT
                ? 'DWD MOSMIX via Bright Sky (12 provincies)'
                : 'DWD MOSMIX via Bright Sky',
            'url' => self::SOURCE_URL,
            'license' => 'CC BY 4.0',
            'license_url' => self::LICENSE_URL,
            'attribution' => 'Weergegevens: Deutscher Wetterdienst (DWD); API: Bright Sky',
            'modified' => true,
            'processed_by' => 'DIS',
            'processing_note' => $this->processingNote(),
        ];
    }

    private function processingNote(): string
    {
        return 'Live DWD MOSMIX-uurverwachting via Bright Sky; DIS aggregeert modelpunten en berekent daglicht. Alleen 10 m AGL-wind is beschikbaar; de oorspronkelijke MOSMIX model-run-tijd wordt niet gepubliceerd.';
    }

    private function request(): PendingRequest
    {
        return $this->configureRequest(Http::acceptJson());
    }

    private function configureRequest(PendingRequest $request): PendingRequest
    {
        return $request
            ->acceptJson()
            ->withHeaders([
                'Accept-Encoding' => 'identity',
                'User-Agent' => 'DIS-UAV-Weather/1.0 (+https://nationaaldroneteam.nl)',
            ])
            ->connectTimeout(
                $this->positiveConfig('bright_sky_connect_timeout_seconds', 2, 1, 3),
            )
            ->timeout(
                $this->positiveConfig('bright_sky_timeout_seconds', 5, 2, 8),
            )
            ->withoutRedirecting()
            ->withOptions($this->boundedOptions())
            ->retry(
                self::REQUEST_ATTEMPTS,
                fn (int $attempt): int => $this->retryDelay($attempt),
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException
                        && ($exception->response->status() === 429
                            || $exception->response->serverError())),
                false,
            );
    }

    /** @return array<string, mixed> */
    private function boundedOptions(): array
    {
        return [
            'allow_redirects' => false,
            'decode_content' => false,
            'http_errors' => false,
            'verify' => true,
            'on_headers' => static function ($response): void {
                $length = trim((string) $response->getHeaderLine('Content-Length'));
                if ($length !== ''
                    && (! ctype_digit($length) || (int) $length > self::MAX_RESPONSE_BYTES)) {
                    throw new \RuntimeException('Bright Sky response length is invalid.');
                }
            },
            'progress' => static function (
                int|float $downloadTotal,
                int|float $downloadedBytes,
                int|float $uploadTotal,
                int|float $uploadedBytes,
            ): void {
                unset($downloadTotal, $uploadTotal, $uploadedBytes);
                if ($downloadedBytes > self::MAX_RESPONSE_BYTES) {
                    throw new \RuntimeException('Bright Sky response exceeded its size limit.');
                }
            },
        ];
    }

    private function assertJsonResponse(Response $response): void
    {
        if (! $response->successful()) {
            throw $response->toException() ?? new RequestException($response);
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if ($contentType !== 'application/json'
            || strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('Bright Sky returned an invalid HTTP response.');
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function requiredNumber(
        array $values,
        string $key,
        float $minimum,
        float $maximum,
    ): float {
        $value = $this->optionalNumber($values[$key] ?? null, $minimum, $maximum);
        if ($value === null) {
            throw new \UnexpectedValueException(
                "Bright Sky parameter {$key} is missing or invalid.",
            );
        }

        return $value;
    }

    private function optionalNumber(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }
        $number = (float) $value;

        return $number >= $minimum && $number <= $maximum ? $number : null;
    }

    /** @return array{sunrise: string, sunset: string} */
    private function sunTimes(
        CarbonImmutable $validAt,
        float $latitude,
        float $longitude,
    ): array {
        $midday = $validAt
            ->setTimezone('Europe/Amsterdam')
            ->startOfDay()
            ->addHours(12);
        $sun = date_sun_info($midday->getTimestamp(), $latitude, $longitude);
        if (! is_int($sun['sunrise'] ?? null) || ! is_int($sun['sunset'] ?? null)) {
            throw new \UnexpectedValueException('Sunrise or sunset could not be calculated.');
        }

        return [
            'sunrise' => CarbonImmutable::createFromTimestampUTC(
                $sun['sunrise'],
            )->toIso8601String(),
            'sunset' => CarbonImmutable::createFromTimestampUTC(
                $sun['sunset'],
            )->toIso8601String(),
        ];
    }

    /** @param list<float> $degrees */
    private function circularMean(array $degrees): ?float
    {
        $x = 0.0;
        $y = 0.0;
        foreach ($degrees as $degree) {
            if (! is_finite($degree) || $degree < 0 || $degree > 360) {
                return null;
            }
            $radians = deg2rad($degree);
            $x += cos($radians);
            $y += sin($radians);
        }
        $magnitude = hypot($x, $y) / max(1, count($degrees));
        if ($magnitude < 0.01) {
            return null;
        }
        $mean = rad2deg(atan2($y, $x));

        return $mean < 0 ? $mean + 360 : $mean;
    }

    /** @param list<mixed> $values */
    private function earliestOptionalTimestamp(array $values): ?string
    {
        $timestamps = [];
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $timestamp = $this->timestamp($value);
            if ($timestamp === null) {
                throw new \UnexpectedValueException(
                    'Bright Sky outlook timestamp is invalid.',
                );
            }
            $timestamps[] = $timestamp;
        }
        if ($timestamps === []) {
            return null;
        }
        usort($timestamps, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $timestamps[0]->toIso8601String();
    }

    /** @param list<mixed> $values */
    private function sharedTimestamp(array $values): ?string
    {
        $timestamps = [];
        foreach ($values as $value) {
            $timestamp = $this->timestamp($value);
            if ($timestamp === null) {
                throw new \UnexpectedValueException(
                    'Bright Sky shared outlook timestamp is invalid.',
                );
            }
            $timestamps[$timestamp->toIso8601String()] = true;
        }

        if (count($timestamps) !== 1) {
            throw new \UnexpectedValueException(
                'Bright Sky point outlook times do not match.',
            );
        }

        return (string) array_key_first($timestamps);
    }

    private function requiredTimestamp(mixed $value): CarbonImmutable
    {
        $timestamp = $this->timestamp($value);
        if ($timestamp === null) {
            throw new \UnexpectedValueException('Bright Sky timestamp is invalid.');
        }

        return $timestamp;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)
            || strlen($value) > 64
            || preg_match(
                '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/D',
                $value,
            ) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function distanceMetres(
        float $latitudeA,
        float $longitudeA,
        float $latitudeB,
        float $longitudeB,
    ): float {
        $latitudeDelta = deg2rad($latitudeB - $latitudeA);
        $longitudeDelta = deg2rad($longitudeB - $longitudeA);
        $a = sin($latitudeDelta / 2) ** 2
            + cos(deg2rad($latitudeA))
            * cos(deg2rad($latitudeB))
            * sin($longitudeDelta / 2) ** 2;

        return 6_371_000 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private function withCurrentStaleness(array $reading): array
    {
        $validAt = $this->timestamp($reading['valid_at'] ?? null);
        $now = CarbonImmutable::now('UTC');
        $maximumAge = $this->positiveConfig(
            'weather_stale_seconds',
            1800,
            300,
            7200,
        );
        if ($validAt === null
            || $validAt->lessThan($now->subSeconds($maximumAge))
            || $validAt->greaterThan($now->addHour()->addMinutes(1))) {
            $reading['stale'] = true;
        }

        return $reading;
    }

    /** @return array<string, mixed> */
    private function lastGoodOrUnavailable(
        string $cacheKey,
        int $expected,
        string $note,
    ): array {
        try {
            $lastGood = Cache::get($cacheKey.':last-good');
            if (is_array($lastGood)) {
                $lastGood['stale'] = true;
                $lastGood['availability_note'] = $note;
                $lastGood['cloud_base_m'] = null;
                $lastGood['cloud_base_sample_count'] = 0;
                $lastGood['cloud_base_complete'] = false;

                return $lastGood;
            }
        } catch (Throwable) {
            // A cache outage must never make an external forecast look safe.
        }

        return $this->unavailable($note, $expected);
    }

    /** @return array<string, mixed> */
    private function unavailable(string $note, int $expected = 0): array
    {
        return [
            'provider_identifier' => 'dwd_mosmix_bright_sky',
            'structured_attribution' => 'DWD_MOSMIX',
            'processing_note' => $this->processingNote(),
            'complete' => false,
            'stale' => false,
            'source' => $this->source($expected),
            'model_run_at' => null,
            'valid_at' => null,
            'measured_at' => null,
            'refreshed_at' => null,
            'sample_count' => 0,
            'expected_sample_count' => $expected,
            'cloud_base_m' => null,
            'cloud_base_sample_count' => 0,
            'cloud_base_expected_sample_count' => 0,
            'cloud_base_complete' => false,
            'availability_note' => $note,
        ];
    }

    private function lockSeconds(int $locationCount): int
    {
        $timeout = $this->positiveConfig('bright_sky_timeout_seconds', 5, 2, 8);
        $waves = 1 + (int) ceil(max(0, $locationCount - 1) / self::POINT_POOL_CONCURRENCY);
        $minimum = ($waves * self::REQUEST_ATTEMPTS * $timeout) + 10;
        $configured = $this->positiveConfig(
            'bright_sky_lock_seconds',
            20,
            10,
            90,
        );

        return min(90, max($minimum, $configured));
    }

    private function retryDelay(int $attempt): int
    {
        return $this->positiveConfig(
            'bright_sky_retry_delay_ms',
            250,
            50,
            1000,
        ) * $attempt;
    }

    private function positiveConfig(
        string $key,
        int $fallback,
        int $minimum,
        int $maximum,
    ): int {
        $value = config("dis.wallboards.uav_forecast.{$key}", $fallback);
        if (! is_numeric($value)) {
            return $fallback;
        }

        return max($minimum, min($maximum, (int) $value));
    }
}
