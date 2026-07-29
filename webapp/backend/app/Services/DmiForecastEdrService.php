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

final class DmiForecastEdrService implements UavWeatherForecastProvider
{
    private const BASE_URL = 'https://opendataapi.dmi.dk/v1/forecastedr';

    private const SOURCE_URL = 'https://www.dmi.dk/friedata/dokumentation/forecast-data-edr-api';

    private const LICENSE_URL = 'https://www.dmi.dk/friedata/dokumentation/terms-of-use';

    private const COLLECTION = 'harmonie_dini_sf';

    private const CACHE_NAMESPACE = 'wallboard:uav-forecast:dmi:v3';

    private const MAX_RESPONSE_BYTES = 1_048_576;

    private const MAX_INSTANCES_RESPONSE_BYTES = 2_097_152;

    private const MAX_LOCATIONS = 12;

    private const MAX_INSTANCES = 16;

    private const MAX_TEMPORAL_VALUES = 96;

    private const MAX_MODEL_RUN_CANDIDATES = 2;

    private const REQUEST_ATTEMPTS = 2;

    private const POINT_POOL_CONCURRENCY = 4;

    private const LOCK_SAFETY_SECONDS = 10;

    private const OUTLOOK_HOURS = 3;

    /** @var list<string> */
    private const PARAMETERS = [
        'temperature-2m',
        'dew-point-temperature-2m',
        'wind-speed-10m',
        'wind-speed-100m',
        'wind-speed-150m',
        'wind-dir-100m',
        'gust-wind-speed-10m',
        'total-precipitation',
        'rain-precipitation-rate',
        'visibility',
        'fraction-of-cloud-cover',
        'low-cloud-cover',
        'medium-cloud-cover',
        'high-cloud-cover',
        'cloud-base',
        'probability-of-lightning',
        'land-percent',
    ];

    /**
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    public function forResolution(array $resolution): array
    {
        if (($resolution['complete'] ?? false) !== true) {
            return $this->unavailable('De gekozen locatie kon niet volledig server-side worden bepaald.');
        }

        $locations = $this->validatedLocations($resolution);
        $expected = (int) ($resolution['expected_locations'] ?? 0);
        if ($locations === null || $expected < 1 || $expected > self::MAX_LOCATIONS || count($locations) !== $expected) {
            return $this->unavailable('Het vereiste aantal DMI-modelpunten kon niet betrouwbaar worden bepaald.');
        }

        $cacheKey = $this->resolutionCacheKey($locations, $expected);

        try {
            $fresh = Cache::get($cacheKey.':fresh');
            if (is_array($fresh)) {
                return $this->withCurrentStaleness($fresh);
            }

            $lock = Cache::lock(
                $cacheKey.':lock',
                $this->lockSeconds(count($locations)),
            );
            if (! $lock->get()) {
                return $this->lastGoodOrUnavailable(
                    $cacheKey,
                    'De DMI-modelpunten worden al opgehaald; deze aanvraag blijft uit veiligheid onbekend.',
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
                'DMI Forecast EDR is niet bereikbaar of gaf ongeldige modeldata terug.',
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
        $lastFailure = null;
        $candidates = $this->modelRunCandidates($now);
        foreach ($candidates as $candidateIndex => $candidate) {
            try {
                $modelRun = $candidate['run'];
                $points = $this->fetchPoints(
                    $locations,
                    $modelRun,
                    $candidate['anchor'],
                    $now,
                );
                if (count($points) !== $expected) {
                    throw new \UnexpectedValueException('DMI returned an incomplete point set.');
                }

                $reading = $this->aggregate($points, $expected, $modelRun, $now);
                $this->assertRequiredOutlook($reading, $candidate['anchor']);
                $this->rememberModelRuns(array_slice($candidates, $candidateIndex));

                return $reading;
            } catch (Throwable $exception) {
                if ($exception instanceof ConnectionException
                    || $exception instanceof RequestException) {
                    throw $exception;
                }
                $lastFailure = $exception;
            }
        }

        throw $lastFailure ?? new \UnexpectedValueException(
            'DMI did not return a complete current model run.',
        );
    }

    /**
     * @return list<array{
     *     run: CarbonImmutable,
     *     anchor: CarbonImmutable,
     *     temporal_values: list<string>
     * }>
     */
    private function modelRunCandidates(CarbonImmutable $now): array
    {
        $cacheKey = self::CACHE_NAMESPACE.':selected-model-run';
        $cached = $this->modelRunCandidatesFromCache(Cache::get($cacheKey.':fresh'), $now);
        if ($cached !== []) {
            return $cached;
        }

        try {
            $response = $this->request()
                ->get(self::BASE_URL.'/collections/'.self::COLLECTION.'/instances');
            $this->assertJsonResponse($response, self::MAX_INSTANCES_RESPONSE_BYTES);
            $payload = $response->json();
            if (! is_array($payload)
                || ! is_array($payload['instances'] ?? null)
                || ! array_is_list($payload['instances'])
                || count($payload['instances']) < 1
                || count($payload['instances']) > self::MAX_INSTANCES) {
                throw new \UnexpectedValueException('DMI instances response is invalid.');
            }

            $candidates = [];
            $seenRuns = [];
            foreach ($payload['instances'] as $instance) {
                if (! is_array($instance) || ! is_string($instance['id'] ?? null)) {
                    throw new \UnexpectedValueException('DMI instance metadata is invalid.');
                }
                $run = $this->modelRunTimestamp($instance['id']);
                if ($run === null) {
                    throw new \UnexpectedValueException('DMI model run timestamp is invalid.');
                }
                $runKey = $run->toIso8601String();
                if (isset($seenRuns[$runKey])) {
                    throw new \UnexpectedValueException('DMI returned duplicate model runs.');
                }
                $seenRuns[$runKey] = true;
                if (! $this->modelRunIsCurrent($run, $now)) {
                    continue;
                }

                $candidate = $this->modelRunCandidate(
                    $run,
                    $instance['extent']['temporal'] ?? null,
                    $now,
                );
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }

            usort(
                $candidates,
                static fn (array $left, array $right): int => $right['run'] <=> $left['run'],
            );
            $candidates = array_slice($candidates, 0, self::MAX_MODEL_RUN_CANDIDATES);
            if ($candidates === []) {
                throw new \UnexpectedValueException(
                    'DMI did not publish a current run covering the required forecast window.',
                );
            }

            return $candidates;
        } catch (Throwable $exception) {
            $lastGood = $this->modelRunCandidatesFromCache(
                Cache::get($cacheKey.':last-good'),
                $now,
            );
            if ($lastGood !== []) {
                return $lastGood;
            }

            throw $exception;
        }
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float}>  $locations
     * @return list<array<string, mixed>>
     */
    private function fetchPoints(
        array $locations,
        CarbonImmutable $modelRun,
        CarbonImmutable $anchor,
        CarbonImmutable $now,
    ): array {
        $from = $anchor->subHour()->format('Y-m-d\TH:i:s\Z');
        $until = $anchor->addHours(self::OUTLOOK_HOURS + 1)->format('Y-m-d\TH:i:s\Z');
        $endpoint = self::BASE_URL
            .'/collections/'.self::COLLECTION
            .'/instances/'.$modelRun->format('Y-m-d\THis\Z')
            .'/position';

        $responses = Http::pool(function (Pool $pool) use ($endpoint, $from, $locations, $until): void {
            foreach ($locations as $index => $location) {
                $pool->as('point-'.$index)
                    ->accept('application/geo+json, application/json')
                    ->withHeaders([
                        'Accept-Encoding' => 'identity',
                        'User-Agent' => 'DIS-UAV-Weather/1.0',
                    ])
                    ->connectTimeout($this->positiveConfig('connect_timeout_seconds', 2, 1, 2))
                    ->timeout($this->positiveConfig('timeout_seconds', 5, 2, 5))
                    ->withoutRedirecting()
                    ->withOptions($this->boundedOptions(self::MAX_RESPONSE_BYTES))
                    ->retry(
                        self::REQUEST_ATTEMPTS,
                        fn (int $attempt): int => $this->retryDelay($attempt),
                        static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                            || ($exception instanceof RequestException
                                && ($exception->response->status() === 429
                                    || $exception->response->serverError())),
                        false,
                    )
                    ->get($endpoint, [
                        'coords' => sprintf(
                            'POINT(%.7F %.7F)',
                            $location['longitude'],
                            $location['latitude'],
                        ),
                        'crs' => 'crs84',
                        'parameter-name' => implode(',', self::PARAMETERS),
                        'datetime' => $from.'/'.$until,
                        'f' => 'GeoJSON',
                    ]);
            }
        }, min(self::POINT_POOL_CONCURRENCY, count($locations)));

        $points = [];
        foreach ($locations as $index => $location) {
            $response = $responses['point-'.$index] ?? null;
            if ($response instanceof Throwable) {
                throw $response;
            }
            if (! $response instanceof Response) {
                throw new ConnectionException('DMI point response is missing.');
            }
            $this->assertJsonResponse($response, self::MAX_RESPONSE_BYTES);
            $payload = $response->json();
            if (! is_array($payload)) {
                throw new \UnexpectedValueException('DMI point payload is invalid.');
            }
            $points[] = $this->parsePoint(
                $payload,
                $location,
                $modelRun,
                $anchor,
                $now,
            );
        }

        return $points;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{label: string, latitude: float, longitude: float}  $location
     * @return array<string, mixed>
     */
    private function parsePoint(
        array $payload,
        array $location,
        CarbonImmutable $modelRun,
        CarbonImmutable $anchor,
        CarbonImmutable $now,
    ): array {
        if (($payload['type'] ?? null) !== 'FeatureCollection'
            || ! is_array($payload['features'] ?? null)
            || ! array_is_list($payload['features'])
            || count($payload['features']) < 2
            || count($payload['features']) > 12) {
            throw new \UnexpectedValueException('DMI GeoJSON feature collection is invalid.');
        }

        $features = [];
        foreach ($payload['features'] as $feature) {
            if (! is_array($feature)) {
                throw new \UnexpectedValueException('DMI GeoJSON feature is invalid.');
            }
            $parsed = $this->parseFeature($feature, $location);
            $key = $parsed['step']->format('Y-m-d\TH:i:s\Z');
            if (isset($features[$key])) {
                throw new \UnexpectedValueException('DMI GeoJSON contains a duplicate forecast step.');
            }
            $features[$key] = $parsed;
        }
        uasort(
            $features,
            static fn (array $a, array $b): int => $a['step'] <=> $b['step'],
        );

        $current = $features[$anchor->format('Y-m-d\TH:i:s\Z')] ?? null;
        if (! is_array($current)
            || abs($anchor->getTimestamp() - $now->getTimestamp())
                > $this->positiveConfig('dmi_valid_window_seconds', 5400, 1800, 7200)) {
            throw new \UnexpectedValueException('DMI did not return the required current forecast step.');
        }

        $properties = $current['properties'];
        $validAt = $current['step'];
        if ($this->requiredNumber($properties, 'land-percent', 0, 1) < 0.5) {
            throw new \UnexpectedValueException('DMI returned a predominantly non-land grid point.');
        }
        $temperature = $this->kelvinToCelsius(
            $this->requiredNumber($properties, 'temperature-2m', 173.15, 333.15),
        );
        $dewPoint = $this->kelvinToCelsius(
            $this->requiredNumber($properties, 'dew-point-temperature-2m', 153.15, 333.15),
        );
        if ($dewPoint > $temperature + 3.0) {
            throw new \UnexpectedValueException('DMI dew point exceeds the temperature bounds.');
        }

        $hourlyPrecipitation = $this->upcomingHourlyPrecipitation($features, $validAt);
        $sun = $this->sunTimes($validAt, $location['latitude'], $location['longitude']);
        $outlook = $this->outlook($features, $validAt);
        $cloudBase = $this->optionalNumber($properties['cloud-base'] ?? null, 0, 60000);
        $stale = ! $this->timestampsAreCurrent($modelRun, $validAt, $now);
        $usableCloudBase = $stale ? null : $cloudBase;

        return [
            'weather_code' => null,
            'temperature_c' => $temperature,
            'dew_point_c' => $dewPoint,
            'dew_point_spread_c' => max(0.0, $temperature - $dewPoint),
            'wind_speed_10m_kmh' => $this->metresPerSecondToKilometresPerHour(
                $this->requiredNumber($properties, 'wind-speed-10m', 0, 140),
            ),
            'wind_speed_100m_kmh' => $this->metresPerSecondToKilometresPerHour(
                $this->requiredNumber($properties, 'wind-speed-100m', 0, 140),
            ),
            'wind_speed_150m_kmh' => $this->metresPerSecondToKilometresPerHour(
                $this->requiredNumber($properties, 'wind-speed-150m', 0, 140),
            ),
            'wind_gust_kmh' => $this->metresPerSecondToKilometresPerHour(
                $this->requiredNumber($properties, 'gust-wind-speed-10m', 0, 140),
            ),
            'wind_direction_degrees' => $this->requiredNumber($properties, 'wind-dir-100m', 0, 360),
            // DMI DINI SF does not publish a normal precipitation probability.
            'precipitation_probability_pct' => null,
            'precipitation_mm' => $hourlyPrecipitation,
            'precipitation_rate_mm_h' => $this->metresPerSecondToHourlyMillimetres(
                $this->requiredNumber($properties, 'rain-precipitation-rate', 0, 2),
            ),
            'visibility_m' => $this->requiredNumber($properties, 'visibility', 0, 100000),
            'cloud_cover_pct' => $this->requiredNumber($properties, 'fraction-of-cloud-cover', 0, 1) * 100,
            'cloud_cover_low_pct' => $this->requiredNumber($properties, 'low-cloud-cover', 0, 100),
            'cloud_cover_mid_pct' => $this->requiredNumber($properties, 'medium-cloud-cover', 0, 100),
            'cloud_cover_high_pct' => $this->requiredNumber($properties, 'high-cloud-cover', 0, 100),
            'cloud_base_m' => $usableCloudBase,
            'cloud_base_sample_count' => $usableCloudBase === null ? 0 : 1,
            'cloud_base_expected_sample_count' => 1,
            'cloud_base_complete' => $usableCloudBase !== null,
            'cloud_base_aggregation' => 'single_grid_point',
            'sunrise' => $sun['sunrise'],
            'sunset' => $sun['sunset'],
            ...$outlook,
            'model_run_at' => $modelRun->toIso8601String(),
            'valid_at' => $validAt->toIso8601String(),
            // Kept for response compatibility; this is a model validity time,
            // not a station observation timestamp.
            'measured_at' => $validAt->toIso8601String(),
            'refreshed_at' => $now->toIso8601String(),
            'stale' => $stale,
            'source' => $this->source(),
            'sample_count' => 1,
            'expected_sample_count' => 1,
            'complete' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $feature
     * @param  array{label: string, latitude: float, longitude: float}  $location
     * @return array{step: CarbonImmutable, properties: array<string, mixed>}
     */
    private function parseFeature(array $feature, array $location): array
    {
        $geometry = $feature['geometry'] ?? null;
        $properties = $feature['properties'] ?? null;
        if (($feature['type'] ?? null) !== 'Feature'
            || ! is_array($geometry)
            || ($geometry['type'] ?? null) !== 'Point'
            || ! is_array($geometry['coordinates'] ?? null)
            || ! array_is_list($geometry['coordinates'])
            || count($geometry['coordinates']) < 2
            || ! is_numeric($geometry['coordinates'][0])
            || ! is_numeric($geometry['coordinates'][1])
            || ! is_array($properties)
            || ! is_string($properties['step'] ?? null)) {
            throw new \UnexpectedValueException('DMI GeoJSON feature fields are invalid.');
        }
        $longitude = (float) $geometry['coordinates'][0];
        $latitude = (float) $geometry['coordinates'][1];
        if (! is_finite($latitude)
            || ! is_finite($longitude)
            || abs($latitude - $location['latitude']) > 0.25
            || abs($longitude - $location['longitude']) > 0.25) {
            throw new \UnexpectedValueException('DMI returned a point outside the requested grid vicinity.');
        }

        $step = $this->timestamp($properties['step']);
        if ($step === null) {
            throw new \UnexpectedValueException('DMI forecast step timestamp is invalid.');
        }

        return ['step' => $step, 'properties' => $properties];
    }

    /**
     * @param  array<string, array{step: CarbonImmutable, properties: array<string, mixed>}>  $features
     *
     * DMI total precipitation is cumulative. The UI's "+1 uur" value
     * therefore uses the increase from the current model anchor to +1 hour.
     */
    private function upcomingHourlyPrecipitation(
        array $features,
        CarbonImmutable $startsAt,
    ): float {
        $start = $features[$startsAt->format('Y-m-d\TH:i:s\Z')]['properties'] ?? null;
        $end = $features[$startsAt->addHour()->format('Y-m-d\TH:i:s\Z')]['properties'] ?? null;
        if (! is_array($start) || ! is_array($end)) {
            throw new \UnexpectedValueException('DMI upcoming hourly precipitation interval is incomplete.');
        }
        $startTotal = $this->requiredNumber($start, 'total-precipitation', 0, 10000);
        $endTotal = $this->requiredNumber($end, 'total-precipitation', 0, 10000);
        $difference = $endTotal - $startTotal;
        if ($difference < -0.001 || $difference > 500) {
            throw new \UnexpectedValueException('DMI upcoming hourly precipitation accumulation is invalid.');
        }

        return max(0.0, $difference);
    }

    /**
     * @param  array<string, array{step: CarbonImmutable, properties: array<string, mixed>}>  $features
     * @return array{
     *     forecast_precipitation_peak_mm_h: float|null,
     *     forecast_precipitation_first_at: string|null,
     *     forecast_precipitation_until: string|null,
     *     thunderstorm_expected: bool|null,
     *     thunderstorm_probability_pct: float|null,
     *     thunderstorm_first_expected_at: string|null,
     *     thunderstorm_forecast_until: string|null
     * }
     */
    private function outlook(array $features, CarbonImmutable $validAt): array
    {
        $steps = [];
        for ($offset = 0; $offset <= self::OUTLOOK_HOURS; $offset++) {
            $step = $validAt->addHours($offset);
            $feature = $features[$step->format('Y-m-d\TH:i:s\Z')] ?? null;
            if (! is_array($feature)) {
                return $this->unknownOutlook();
            }
            $steps[] = $feature;
        }

        $rates = [];
        $ratesComplete = true;
        $probabilities = [];
        $probabilitiesComplete = true;
        foreach ($steps as $index => $feature) {
            // The UI's 0–3 hour window is three forward intervals:
            // validAt→+1h, +1h→+2h and +2h→+3h.
            if ($ratesComplete && $index < self::OUTLOOK_HOURS) {
                try {
                    $rates[] = $this->upcomingHourlyPrecipitation($features, $feature['step']);
                } catch (Throwable) {
                    $ratesComplete = false;
                    $rates = [];
                }
            }
            $probability = $this->optionalNumber(
                $feature['properties']['probability-of-lightning'] ?? null,
                0,
                1,
            );
            if ($probability === null) {
                $probabilitiesComplete = false;
                $probabilities = [];
            } elseif ($probabilitiesComplete) {
                $probabilities[] = $probability * 100;
            }
        }
        if (! $ratesComplete || count($rates) !== self::OUTLOOK_HOURS) {
            $rates = [];
        }
        if (! $probabilitiesComplete || count($probabilities) !== 4) {
            $probabilities = [];
        }

        $firstPrecipitation = null;
        foreach ($rates as $index => $rate) {
            if ($rate > 0.001) {
                $firstPrecipitation = $steps[$index]['step']->toIso8601String();
                break;
            }
        }
        $firstLightning = null;
        foreach ($probabilities as $index => $probability) {
            if ($probability > 0.0) {
                $firstLightning = $steps[$index]['step']->toIso8601String();
                break;
            }
        }
        $until = $steps[array_key_last($steps)]['step']->toIso8601String();

        return [
            'forecast_precipitation_peak_mm_h' => $rates === [] ? null : max($rates),
            'forecast_precipitation_first_at' => $rates === [] ? null : $firstPrecipitation,
            'forecast_precipitation_until' => $rates === [] ? null : $until,
            'thunderstorm_expected' => $probabilities === [] ? null : $firstLightning !== null,
            'thunderstorm_probability_pct' => $probabilities === [] ? null : max($probabilities),
            'thunderstorm_first_expected_at' => $probabilities === [] ? null : $firstLightning,
            'thunderstorm_forecast_until' => $probabilities === [] ? null : $until,
        ];
    }

    /** @return array<string, null> */
    private function unknownOutlook(): array
    {
        return [
            'forecast_precipitation_peak_mm_h' => null,
            'forecast_precipitation_first_at' => null,
            'forecast_precipitation_until' => null,
            'thunderstorm_expected' => null,
            'thunderstorm_probability_pct' => null,
            'thunderstorm_first_expected_at' => null,
            'thunderstorm_forecast_until' => null,
        ];
    }

    /** @param array<string, mixed> $reading */
    private function assertRequiredOutlook(
        array $reading,
        CarbonImmutable $anchor,
    ): void {
        $until = $anchor->addHours(self::OUTLOOK_HOURS);
        $precipitationUntil = $this->timestamp(
            $reading['forecast_precipitation_until'] ?? null,
        );
        $thunderstormUntil = $this->timestamp(
            $reading['thunderstorm_forecast_until'] ?? null,
        );
        if (! is_numeric($reading['forecast_precipitation_peak_mm_h'] ?? null)
            || ! is_bool($reading['thunderstorm_expected'] ?? null)
            || ! is_numeric($reading['thunderstorm_probability_pct'] ?? null)
            || $precipitationUntil === null
            || ! $precipitationUntil->equalTo($until)
            || $thunderstormUntil === null
            || ! $thunderstormUntil->equalTo($until)) {
            throw new \UnexpectedValueException('DMI required three-hour outlook is incomplete.');
        }

        foreach ([
            'forecast_precipitation_first_at',
            'thunderstorm_first_expected_at',
        ] as $key) {
            if (($reading[$key] ?? null) === null) {
                continue;
            }
            $first = $this->timestamp($reading[$key]);
            if ($first === null
                || $first->lessThan($anchor)
                || $first->greaterThan($until)) {
                throw new \UnexpectedValueException('DMI outlook timestamp is invalid.');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @return array<string, mixed>
     */
    private function aggregate(
        array $points,
        int $expected,
        CarbonImmutable $modelRun,
        CarbonImmutable $now,
    ): array {
        if (count($points) !== $expected
            || collect($points)->contains(
                static fn (array $point): bool => ($point['complete'] ?? false) !== true
                    || ($point['model_run_at'] ?? null) !== $modelRun->toIso8601String(),
            )) {
            throw new \UnexpectedValueException('DMI point aggregation is incomplete.');
        }

        $validAt = $points[0]['valid_at'] ?? null;
        if (! is_string($validAt)
            || collect($points)->contains(
                static fn (array $point): bool => ($point['valid_at'] ?? null) !== $validAt,
            )) {
            throw new \UnexpectedValueException('DMI point validity times do not match.');
        }
        $valid = $this->requiredTimestamp($validAt);
        $stale = collect($points)->contains(
            static fn (array $point): bool => ($point['stale'] ?? true) === true,
        ) || ! $this->timestampsAreCurrent($modelRun, $valid, $now);

        $averageKeys = [
            'temperature_c',
            'dew_point_c',
            'dew_point_spread_c',
            'wind_speed_10m_kmh',
            'wind_speed_100m_kmh',
            'wind_speed_150m_kmh',
            'wind_gust_kmh',
            'precipitation_mm',
            'precipitation_rate_mm_h',
            'visibility_m',
            'cloud_cover_pct',
            'cloud_cover_low_pct',
            'cloud_cover_mid_pct',
            'cloud_cover_high_pct',
        ];
        $result = [];
        foreach ($averageKeys as $key) {
            $values = array_column($points, $key);
            if (count($values) !== $expected
                || collect($values)->contains(static fn (mixed $value): bool => ! is_numeric($value))) {
                throw new \UnexpectedValueException('DMI point value aggregation is incomplete.');
            }
            $result[$key] = array_sum($values) / $expected;
        }
        $result['wind_speed_kmh'] = $result['wind_speed_100m_kmh'];
        $result['wind_direction_degrees'] = $this->circularMean(array_map(
            static fn (array $point): float => (float) $point['wind_direction_degrees'],
            $points,
        ));
        $result['weather_code'] = null;
        $result['precipitation_probability_pct'] = null;

        $cloudBases = collect($points)
            ->filter(static fn (array $point): bool => ($point['stale'] ?? true) === false)
            ->pluck('cloud_base_m')
            ->filter(static fn (mixed $value): bool => is_numeric($value))
            ->map(static fn (mixed $value): float => (float) $value)
            ->values()
            ->all();
        $cloudBaseComplete = ! $stale && count($cloudBases) === $expected;
        $result['cloud_base_m'] = $cloudBaseComplete ? min($cloudBases) : null;
        $result['cloud_base_sample_count'] = count($cloudBases);
        $result['cloud_base_expected_sample_count'] = $expected;
        $result['cloud_base_complete'] = $cloudBaseComplete;
        $result['cloud_base_aggregation'] = $expected === 1
            ? 'single_grid_point'
            : 'minimum_of_province_samples';

        $precipitationPeaks = array_column($points, 'forecast_precipitation_peak_mm_h');
        $precipitationComplete = count($precipitationPeaks) === $expected
            && ! collect($precipitationPeaks)->contains(static fn (mixed $value): bool => ! is_numeric($value));
        $result['forecast_precipitation_peak_mm_h'] = $precipitationComplete
            ? max(array_map('floatval', $precipitationPeaks))
            : null;
        $result['forecast_precipitation_first_at'] = $precipitationComplete
            ? $this->earliestOptionalTimestamp(array_column($points, 'forecast_precipitation_first_at'))
            : null;
        $result['forecast_precipitation_until'] = $precipitationComplete
            ? $this->sharedTimestamp(array_column($points, 'forecast_precipitation_until'))
            : null;

        $thunderstormComplete = collect($points)->every(
            static fn (array $point): bool => is_bool($point['thunderstorm_expected'] ?? null)
                && is_numeric($point['thunderstorm_probability_pct'] ?? null)
                && is_string($point['thunderstorm_forecast_until'] ?? null),
        );
        $result['thunderstorm_expected'] = $thunderstormComplete
            ? collect($points)->contains(
                static fn (array $point): bool => $point['thunderstorm_expected'] === true,
            )
            : null;
        $result['thunderstorm_probability_pct'] = $thunderstormComplete
            ? max(array_map('floatval', array_column($points, 'thunderstorm_probability_pct')))
            : null;
        $result['thunderstorm_first_expected_at'] = $thunderstormComplete
            ? $this->earliestOptionalTimestamp(array_column($points, 'thunderstorm_first_expected_at'))
            : null;
        $result['thunderstorm_forecast_until'] = $thunderstormComplete
            ? $this->sharedTimestamp(array_column($points, 'thunderstorm_forecast_until'))
            : null;

        $sunrises = array_map(
            fn (array $point): CarbonImmutable => $this->requiredTimestamp($point['sunrise'] ?? null),
            $points,
        );
        $sunsets = array_map(
            fn (array $point): CarbonImmutable => $this->requiredTimestamp($point['sunset'] ?? null),
            $points,
        );
        usort($sunrises, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
        usort($sunsets, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return [
            ...$result,
            'sunrise_earliest' => $sunrises[0]->toIso8601String(),
            'sunrise_latest' => $sunrises[array_key_last($sunrises)]->toIso8601String(),
            'sunset_earliest' => $sunsets[0]->toIso8601String(),
            'sunset_latest' => $sunsets[array_key_last($sunsets)]->toIso8601String(),
            'model_run_at' => $modelRun->toIso8601String(),
            'valid_at' => $valid->toIso8601String(),
            'measured_at' => $valid->toIso8601String(),
            'refreshed_at' => $now->toIso8601String(),
            'stale' => $stale,
            'source' => $this->source($expected),
            'sample_count' => $expected,
            'expected_sample_count' => $expected,
            'complete' => true,
        ];
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

    /** @return array<string, mixed> */
    private function lastGoodOrUnavailable(string $cacheKey, string $note): array
    {
        try {
            $lastGood = Cache::get($cacheKey.':last-good');
            if (is_array($lastGood)) {
                $lastGood['stale'] = true;
                $lastGood['availability_note'] = $note;

                return $this->withoutUsableCloudBase($lastGood);
            }
        } catch (Throwable) {
            // A cache outage must never make an external forecast look safe.
        }

        return $this->unavailable($note);
    }

    /** @param array<string, mixed> $reading
     * @return array<string, mixed>
     */
    private function withCurrentStaleness(array $reading): array
    {
        $modelRun = $this->timestamp($reading['model_run_at'] ?? null);
        $validAt = $this->timestamp($reading['valid_at'] ?? null);
        if ($modelRun === null
            || $validAt === null
            || ! $this->timestampsAreCurrent($modelRun, $validAt, CarbonImmutable::now('UTC'))) {
            $reading['stale'] = true;

            return $this->withoutUsableCloudBase($reading);
        }

        return $reading;
    }

    /**
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>
     */
    private function withoutUsableCloudBase(array $reading): array
    {
        $reading['cloud_base_m'] = null;
        $reading['cloud_base_sample_count'] = 0;
        $reading['cloud_base_complete'] = false;

        return $reading;
    }

    /** @return array<string, mixed> */
    private function unavailable(string $note): array
    {
        return [
            'complete' => false,
            'stale' => false,
            'source' => $this->source(),
            'model_run_at' => null,
            'valid_at' => null,
            'measured_at' => null,
            'refreshed_at' => null,
            'sample_count' => 0,
            'expected_sample_count' => 0,
            'cloud_base_m' => null,
            'cloud_base_sample_count' => 0,
            'cloud_base_expected_sample_count' => 0,
            'cloud_base_complete' => false,
            'availability_note' => $note,
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     url: string,
     *     license: string,
     *     license_url: string,
     *     attribution: string,
     *     modified: bool,
     *     processed_by: string
     * }
     */
    private function source(?int $expected = null): array
    {
        return [
            'name' => $expected === WallboardForecastLocationService::NETHERLANDS_PROVINCE_COUNT
                ? 'DMI HARMONIE DINI (12 provincies)'
                : 'DMI HARMONIE DINI',
            'url' => self::SOURCE_URL,
            'license' => 'CC BY 4.0',
            'license_url' => self::LICENSE_URL,
            'attribution' => 'Contains modified DMI data',
            'modified' => true,
            'processed_by' => 'DIS',
        ];
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders([
                'Accept-Encoding' => 'identity',
                'User-Agent' => 'DIS-UAV-Weather/1.0',
            ])
            ->connectTimeout($this->positiveConfig('connect_timeout_seconds', 2, 1, 2))
            ->timeout($this->positiveConfig('timeout_seconds', 5, 2, 5))
            ->withoutRedirecting()
            ->withOptions($this->boundedOptions(self::MAX_INSTANCES_RESPONSE_BYTES))
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
    private function boundedOptions(int $maximumBytes): array
    {
        return [
            'allow_redirects' => false,
            'decode_content' => false,
            'http_errors' => false,
            'verify' => true,
            'on_headers' => static function ($response) use ($maximumBytes): void {
                $length = trim((string) $response->getHeaderLine('Content-Length'));
                if ($length !== '' && (! ctype_digit($length) || (int) $length > $maximumBytes)) {
                    throw new \RuntimeException('DMI response length is invalid.');
                }
            },
            'progress' => static function (
                int|float $downloadTotal,
                int|float $downloadedBytes,
                int|float $uploadTotal,
                int|float $uploadedBytes,
            ) use ($maximumBytes): void {
                unset($downloadTotal, $uploadTotal, $uploadedBytes);
                if ($downloadedBytes > $maximumBytes) {
                    throw new \RuntimeException('DMI response exceeded its size limit.');
                }
            },
        ];
    }

    private function assertJsonResponse(Response $response, int $maximumBytes): void
    {
        if (! $response->successful()) {
            throw $response->toException()
                ?? new RequestException($response);
        }
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if (! in_array($contentType, ['application/json', 'application/geo+json'], true)
            || strlen($response->body()) > $maximumBytes) {
            throw new \RuntimeException('DMI returned an invalid HTTP response.');
        }
    }

    /** @param array<string, mixed> $properties */
    private function requiredNumber(
        array $properties,
        string $key,
        float $minimum,
        float $maximum,
    ): float {
        $value = $this->optionalNumber($properties[$key] ?? null, $minimum, $maximum);
        if ($value === null) {
            throw new \UnexpectedValueException("DMI parameter {$key} is missing or invalid.");
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

    private function metresPerSecondToKilometresPerHour(float $value): float
    {
        return $value * 3.6;
    }

    private function metresPerSecondToHourlyMillimetres(float $value): float
    {
        return $value * 3600;
    }

    private function kelvinToCelsius(float $value): float
    {
        return $value - 273.15;
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
            'sunrise' => CarbonImmutable::createFromTimestampUTC($sun['sunrise'])->toIso8601String(),
            'sunset' => CarbonImmutable::createFromTimestampUTC($sun['sunset'])->toIso8601String(),
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
                return null;
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
                return null;
            }
            $timestamps[$timestamp->toIso8601String()] = true;
        }

        return count($timestamps) === 1 ? (string) array_key_first($timestamps) : null;
    }

    private function requiredTimestamp(mixed $value): CarbonImmutable
    {
        $timestamp = $this->timestamp($value);
        if ($timestamp === null) {
            throw new \UnexpectedValueException('DMI timestamp is invalid.');
        }

        return $timestamp;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)
            || strlen($value) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function modelRunTimestamp(string $value): ?CarbonImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{6}Z\z/D', $value) !== 1) {
            return null;
        }

        try {
            $run = CarbonImmutable::createFromFormat('!Y-m-d\THis\Z', $value, 'UTC');

            return $run instanceof CarbonImmutable
                && $run->format('Y-m-d\THis\Z') === $value
                ? $run->utc()
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *     run: CarbonImmutable,
     *     anchor: CarbonImmutable,
     *     temporal_values: list<string>
     * }|null
     */
    private function modelRunCandidate(
        CarbonImmutable $run,
        mixed $temporal,
        CarbonImmutable $now,
    ): ?array {
        if (! is_array($temporal)
            || ! is_array($temporal['values'] ?? null)
            || ! array_is_list($temporal['values'])
            || count($temporal['values']) < 1
            || count($temporal['values']) > self::MAX_TEMPORAL_VALUES
            || ! is_array($temporal['interval'] ?? null)
            || ! array_is_list($temporal['interval'])
            || count($temporal['interval']) !== 1
            || ! is_array($temporal['interval'][0])
            || ! array_is_list($temporal['interval'][0])
            || count($temporal['interval'][0]) !== 2) {
            return null;
        }

        $intervalStart = $this->hourlyTimestamp($temporal['interval'][0][0] ?? null);
        $intervalEnd = $this->hourlyTimestamp($temporal['interval'][0][1] ?? null);
        if ($intervalStart === null
            || $intervalEnd === null
            || $intervalEnd->lessThan($intervalStart)) {
            return null;
        }

        $values = [];
        $steps = [];
        $previous = null;
        foreach ($temporal['values'] as $rawValue) {
            $step = $this->hourlyTimestamp($rawValue);
            if ($step === null
                || ($previous !== null
                    && $step->getTimestamp() - $previous->getTimestamp() !== 3600)) {
                return null;
            }
            $key = $step->format('Y-m-d\TH:i:s\Z');
            $values[] = $key;
            $steps[$key] = $step;
            $previous = $step;
        }
        if ($values[0] !== $intervalStart->format('Y-m-d\TH:i:s\Z')
            || $values[array_key_last($values)] !== $intervalEnd->format('Y-m-d\TH:i:s\Z')) {
            return null;
        }

        $anchor = null;
        $anchorDistance = null;
        foreach ($steps as $step) {
            $distance = abs($step->getTimestamp() - $now->getTimestamp());
            if ($distance > $this->positiveConfig('dmi_valid_window_seconds', 5400, 1800, 7200)) {
                continue;
            }
            if ($anchor === null || $distance < $anchorDistance) {
                $anchor = $step;
                $anchorDistance = $distance;
            }
        }
        if ($anchor === null) {
            return null;
        }
        for ($offset = 0; $offset <= self::OUTLOOK_HOURS; $offset++) {
            if (! isset($steps[$anchor->addHours($offset)->format('Y-m-d\TH:i:s\Z')])) {
                return null;
            }
        }

        return [
            'run' => $run,
            'anchor' => $anchor,
            'temporal_values' => $values,
        ];
    }

    private function hourlyTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:00:00Z\z/D', $value) !== 1) {
            return null;
        }
        $timestamp = $this->timestamp($value);

        return $timestamp !== null
            && $timestamp->format('Y-m-d\TH:i:s\Z') === $value
            ? $timestamp
            : null;
    }

    /**
     * @return array{
     *     run: CarbonImmutable,
     *     anchor: CarbonImmutable,
     *     temporal_values: list<string>
     * }|null
     */
    private function modelRunCandidateFromCache(mixed $value, CarbonImmutable $now): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $run = $this->timestamp($value['model_run_at'] ?? null);
        $values = $value['temporal_values'] ?? null;
        if ($run === null
            || ! $this->modelRunIsCurrent($run, $now)
            || ! is_array($values)
            || ! array_is_list($values)
            || $values === []) {
            return null;
        }

        return $this->modelRunCandidate(
            $run,
            [
                'values' => $values,
                'interval' => [[$values[0], $values[array_key_last($values)]]],
            ],
            $now,
        );
    }

    /**
     * @return list<array{
     *     run: CarbonImmutable,
     *     anchor: CarbonImmutable,
     *     temporal_values: list<string>
     * }>
     */
    private function modelRunCandidatesFromCache(mixed $value, CarbonImmutable $now): array
    {
        if (! is_array($value)
            || ! is_array($value['candidates'] ?? null)
            || ! array_is_list($value['candidates'])
            || count($value['candidates']) < 1
            || count($value['candidates']) > self::MAX_MODEL_RUN_CANDIDATES) {
            return [];
        }

        $candidates = [];
        $previousRun = null;
        foreach ($value['candidates'] as $cachedCandidate) {
            $candidate = $this->modelRunCandidateFromCache($cachedCandidate, $now);
            if ($candidate === null
                || ($previousRun !== null && ! $candidate['run']->lessThan($previousRun))) {
                return [];
            }
            $candidates[] = $candidate;
            $previousRun = $candidate['run'];
        }

        return $candidates;
    }

    /**
     * @param  list<array{
     *     run: CarbonImmutable,
     *     anchor: CarbonImmutable,
     *     temporal_values: list<string>
     * }>  $candidates
     */
    private function rememberModelRuns(array $candidates): void
    {
        $cacheKey = self::CACHE_NAMESPACE.':selected-model-run';
        $entry = [
            'candidates' => array_map(
                static fn (array $candidate): array => [
                    'model_run_at' => $candidate['run']->toIso8601String(),
                    'temporal_values' => $candidate['temporal_values'],
                ],
                array_slice($candidates, 0, self::MAX_MODEL_RUN_CANDIDATES),
            ),
        ];
        Cache::put(
            $cacheKey.':fresh',
            $entry,
            $this->positiveConfig('dmi_model_cache_seconds', 600, 60, 1800),
        );
        Cache::put(
            $cacheKey.':last-good',
            $entry,
            $this->positiveConfig('last_good_cache_seconds', 21600, 900, 86400),
        );
    }

    private function modelRunIsCurrent(CarbonImmutable $modelRun, CarbonImmutable $now): bool
    {
        return ! $modelRun->greaterThan($now->addMinutes(10))
            && ! $modelRun->lessThan(
                $now->subSeconds($this->positiveConfig('dmi_model_stale_seconds', 21600, 3600, 43200)),
            );
    }

    private function timestampsAreCurrent(
        CarbonImmutable $modelRun,
        CarbonImmutable $validAt,
        CarbonImmutable $now,
    ): bool {
        return $this->modelRunIsCurrent($modelRun, $now)
            && abs($validAt->getTimestamp() - $now->getTimestamp())
                <= $this->positiveConfig('dmi_valid_window_seconds', 5400, 1800, 7200);
    }

    private function positiveConfig(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = (int) config('dis.wallboards.uav_forecast.'.$key, $default);

        return max($minimum, min($maximum, $value));
    }

    private function lockSeconds(int $locationCount): int
    {
        $timeoutSeconds = $this->positiveConfig('timeout_seconds', 5, 2, 5);
        $retryBaseMilliseconds = $this->positiveConfig('dmi_retry_delay_ms', 250, 100, 500);
        $retrySleepMilliseconds = 0;
        for ($attempt = 1; $attempt < self::REQUEST_ATTEMPTS; $attempt++) {
            $retrySleepMilliseconds += min(
                2000,
                $retryBaseMilliseconds * (2 ** max(0, $attempt - 1)),
            ) + min(100, $retryBaseMilliseconds);
        }

        $singleRequestSeconds = (self::REQUEST_ATTEMPTS * $timeoutSeconds)
            + (int) ceil($retrySleepMilliseconds / 1000);
        $pointWaves = (int) ceil(
            max(1, min(self::MAX_LOCATIONS, $locationCount)) / self::POINT_POOL_CONCURRENCY,
        );
        // One instance request is followed by at most two candidate point pools.
        $requestWaves = 1 + (self::MAX_MODEL_RUN_CANDIDATES * $pointWaves);
        $worstCaseSeconds = ($requestWaves * $singleRequestSeconds)
            + self::LOCK_SAFETY_SECONDS;
        $configuredSeconds = $this->positiveConfig('dmi_lock_seconds', 20, 5, 90);

        return min(90, max($configuredSeconds, $worstCaseSeconds));
    }

    private function retryDelay(int $attempt): int
    {
        $base = $this->positiveConfig('dmi_retry_delay_ms', 250, 100, 500);
        $exponential = min(1000, $base * (2 ** max(0, $attempt - 1)));

        return $exponential + random_int(0, min(100, $base));
    }
}
