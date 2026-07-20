<?php

namespace App\Services;

use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WallboardForecastService
{
    private const LOCAL_TIMEZONE = 'Europe/Amsterdam';

    private const CACHE_NAMESPACE = 'wallboard:uav-forecast:v2';

    private const WEATHER_URL = 'https://api.open-meteo.com/v1/forecast';

    private const KP_CURRENT_URL = 'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json';

    private const KP_FALLBACK_URL = 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json';

    /** @var list<string> */
    private const REQUIRED_WEATHER_FIELDS = [
        'weather_code',
        'temperature_c',
        'dew_point_c',
        'wind_speed_10m_kmh',
        'wind_speed_80m_kmh',
        'wind_speed_kmh',
        'wind_gust_kmh',
        'wind_direction_degrees',
        'precipitation_probability_pct',
        'precipitation_mm',
        'cloud_cover_pct',
        'visibility_m',
        'sunrise',
        'sunset',
    ];

    public function __construct(
        private readonly WallboardForecastClassifier $classifier,
        private readonly WallboardForecastLocationService $locations,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, array<string, mixed>>
     */
    public function pages(array $configuration): array
    {
        $pages = collect((array) ($configuration['pages'] ?? []))
            ->filter(fn (mixed $page): bool => is_array($page) && ($page['type'] ?? null) === 'uav_forecast')
            ->values();
        if ($pages->isEmpty()) {
            return [];
        }

        $resolvedPages = [];
        $requestedLocations = [];
        foreach ($pages as $page) {
            $pageId = (string) ($page['id'] ?? '');
            if ($pageId === '') {
                continue;
            }
            $resolution = $this->locations->resolve((array) ($page['options'] ?? []));
            $resolvedPages[$pageId] = ['page' => $page, 'resolution' => $resolution];
            if (! $resolution['complete']) {
                continue;
            }
            foreach ($resolution['locations'] as $location) {
                $requestedLocations[$this->weatherCacheKey($location['latitude'], $location['longitude'])] = $location;
            }
        }

        $weatherByLocation = $this->weatherReadings(array_values($requestedLocations));
        $kp = $this->kpReading();
        $result = [];

        foreach ($resolvedPages as $pageId => ['page' => $page, 'resolution' => $resolution]) {
            $options = (array) ($page['options'] ?? []);
            $weather = $this->aggregateWeather($resolution, $weatherByLocation);
            $condition = $this->condition($weather);
            $windMetric = $this->metric('wind_speed_kmh', 'Wind op 120 m AGL', $weather['wind_speed_kmh'] ?? null, 'km/u', $weather, 1);
            $windMetric['height_samples_agl_m'] = [
                ['height_agl_m' => 10, 'speed_kmh' => $this->roundedOrNull($weather['wind_speed_10m_kmh'] ?? null, 1)],
                ['height_agl_m' => 80, 'speed_kmh' => $this->roundedOrNull($weather['wind_speed_80m_kmh'] ?? null, 1)],
                ['height_agl_m' => 120, 'speed_kmh' => $this->roundedOrNull($weather['wind_speed_kmh'] ?? null, 1)],
            ];
            $windMetric['max_non_red_wind_height_agl_m'] = $weather['max_non_red_wind_height_agl_m'] ?? null;
            $metrics = [
                $condition,
                $this->metric('temperature_c', 'Temperatuur', $weather['temperature_c'] ?? null, '°C', $weather, 1),
                $this->metric(
                    'dew_point_c',
                    'Dauwpunt',
                    $weather['dew_point_c'] ?? null,
                    '°C',
                    $weather,
                    1,
                    $weather['dew_point_spread_c'] ?? null,
                ),
                $windMetric,
                $this->metric('wind_gust_kmh', 'Windstoten op 10 m AGL', $weather['wind_gust_kmh'] ?? null, 'km/u', $weather, 1),
                $this->metric('wind_direction_degrees', 'Windrichting op 120 m AGL', $weather['wind_direction_degrees'] ?? null, '°', $weather, 0),
                $this->metric(
                    'precipitation_probability_pct',
                    'Neerslagkans',
                    $weather['precipitation_probability_pct'] ?? null,
                    '%',
                    $weather,
                    0,
                ),
                $this->metric('precipitation_mm', 'Neerslag', $weather['precipitation_mm'] ?? null, 'mm', $weather, 1),
                $this->metric('cloud_cover_pct', 'Bewolking', $weather['cloud_cover_pct'] ?? null, '%', $weather, 0),
                $this->metric('visibility_m', 'Zicht', $weather['visibility_m'] ?? null, 'm', $weather, 0),
                $this->metric('kp_index', 'Geomagnetische activiteit', $kp['value'] ?? null, 'Kp', $kp, 2),
                $this->unknownGnssMetric('gnss_satellites', 'Zichtbare GNSS-satellieten'),
                $this->unknownGnssMetric('gnss_satellites_fix', 'GNSS-satellieten in fix'),
            ];

            $centre = $resolution['complete']
                ? $this->centre($resolution['locations'])
                : ['latitude' => null, 'longitude' => null];
            $result[$pageId] = [
                'location' => [
                    'mode' => $resolution['mode'],
                    'label' => $resolution['label'],
                    'latitude' => $centre['latitude'],
                    'longitude' => $centre['longitude'],
                ],
                'aggregation' => [
                    'type' => $resolution['mode'] === WallboardForecastLocationService::MODE_NETHERLANDS
                        ? 'province_average'
                        : 'single_location',
                    'sample_count' => (int) ($weather['sample_count'] ?? 0),
                    'expected_sample_count' => $resolution['expected_locations'],
                    'complete' => (bool) ($weather['complete'] ?? false),
                    'fresh' => (bool) ($weather['complete'] ?? false) && ! (bool) ($weather['stale'] ?? false),
                ],
                'visible_blocks' => array_values((array) ($options['visible_blocks'] ?? WallboardConfiguration::FORECAST_VISIBLE_BLOCKS)),
                'overall_status' => $this->classifier->overall($metrics),
                'generated_at' => $this->forecastGeneratedAt($weather, $kp),
                'condition' => [
                    'code' => $condition['value'],
                    'label' => $condition['display_value'],
                    'status' => $condition['status'],
                    'stale' => $condition['stale'],
                    'source' => $condition['source'],
                    'measured_at' => $condition['measured_at'],
                ],
                'daylight' => $this->daylight($weather),
                'wind_profile' => [
                    'samples' => $windMetric['height_samples_agl_m'],
                    'max_non_red_wind_height_agl_m' => $windMetric['max_non_red_wind_height_agl_m'],
                    'stale' => (bool) ($weather['stale'] ?? false),
                ],
                'metrics' => $metrics,
                'scope_note' => $resolution['mode'] === WallboardForecastLocationService::MODE_NETHERLANDS
                    ? 'Rekenkundig gemiddelde van actuele waarden voor exact alle 12 Nederlandse provincies; windrichting is een circulair gemiddelde en zonopkomst/-ondergang worden als landelijke tijdsrange getoond.'
                    : 'Actuele modelwaarden voor het server-side opgeloste adres.',
                'disclaimer' => 'Indicatief vliegadvies. Wind is modelwind op circa 120 m boven maaiveld; windstoten zijn alleen als 10 m-grondwaarde beschikbaar. Toestellimieten, missieprofiel, lokale weerswaarneming, luchtruimregels en gezaghebbende operationele beoordeling gaan altijd voor.',
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>
     */
    private function metric(
        string $key,
        string $label,
        mixed $rawValue,
        ?string $unit,
        array $reading,
        int $precision,
        mixed $classificationValue = null,
    ): array {
        $value = is_numeric($rawValue) && is_finite((float) $rawValue)
            ? round((float) $rawValue, $precision)
            : null;
        $stale = (bool) ($reading['stale'] ?? false);
        $valueForClassification = $classificationValue === null ? $value : $classificationValue;
        $classification = $this->classifier->classify(
            $key,
            is_numeric($valueForClassification) ? (float) $valueForClassification : null,
            $stale,
        );
        $explanation = $classification['explanation'];
        if ($value === null && is_string($reading['availability_note'] ?? null)) {
            $explanation .= ' '.$reading['availability_note'];
        }

        $height = match ($key) {
            'wind_speed_kmh', 'wind_direction_degrees' => ['altitude_m' => 120, 'source_height_label' => '120 m boven maaiveld'],
            'wind_gust_kmh' => ['altitude_m' => 10, 'source_height_label' => '10 m boven maaiveld (grondwaarde)'],
            'temperature_c', 'dew_point_c' => ['altitude_m' => 2, 'source_height_label' => '2 m boven maaiveld (grondwaarde)'],
            default => ['altitude_m' => null, 'source_height_label' => 'oppervlaktewaarde'],
        };

        $metric = [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'unit' => $unit,
            'status' => $classification['status'],
            'stale' => $stale,
            'source' => $reading['source'] ?? ['name' => 'Onbekend', 'url' => null],
            'measured_at' => $reading['measured_at'] ?? null,
            'explanation' => $explanation,
            ...$height,
        ];
        if ($key === 'visibility_m') {
            $metric['display_value'] = $value === null
                ? null
                : ($value >= 10000
                    ? number_format($value / 1000, 2, '.', '')
                    : number_format($value, 0, '.', ''));
            $metric['display_unit'] = $value !== null && $value >= 10000 ? 'km' : 'm';
        }

        return $metric;
    }

    /** @return array<string, mixed> */
    private function condition(array $weather): array
    {
        $metric = $this->metric(
            'weather_code',
            'Weer',
            $weather['weather_code'] ?? null,
            'WMO',
            $weather,
            0,
        );
        $code = is_numeric($metric['value']) ? (int) $metric['value'] : null;
        $metric['display_value'] = $code === null
            ? 'Onbekend'
            : $this->classifier->weatherCodeLabel($code);

        return $metric;
    }

    /** @return array<string, mixed> */
    private function unknownGnssMetric(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => null,
            'unit' => null,
            'status' => WallboardForecastClassifier::STATUS_UNKNOWN,
            'stale' => false,
            'source' => ['name' => 'Geen receiverdata beschikbaar', 'url' => null],
            'measured_at' => null,
            'explanation' => 'Zonder gevalideerde receiverdata van een lokale GNSS-ontvanger blijft deze waarde fail-closed onbekend.',
            'altitude_m' => null,
            'source_height_label' => null,
        ];
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float}>  $locations
     * @return array<string, array<string, mixed>>
     */
    private function weatherReadings(array $locations): array
    {
        $readings = [];
        $missing = [];
        foreach ($locations as $location) {
            $key = $this->weatherCacheKey($location['latitude'], $location['longitude']);
            $fresh = Cache::get($key.':fresh');
            if (is_array($fresh)) {
                $readings[$key] = $fresh;
            } else {
                $missing[$key] = $location;
            }
        }

        if ($missing !== []) {
            try {
                $loaded = $this->fetchWeatherBatch(array_values($missing));
                foreach ($loaded as $key => $reading) {
                    $this->storeReading($key, $reading);
                    $readings[$key] = $reading;
                }
            } catch (Throwable) {
                // Missing locations are handled with last-good data below.
            }

            foreach ($missing as $key => $location) {
                if (isset($readings[$key])) {
                    continue;
                }
                $lastGood = Cache::get($key.':last-good');
                if (is_array($lastGood)) {
                    $lastGood['stale'] = true;
                    $readings[$key] = $lastGood;
                }
            }
        }

        return $readings;
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float}>  $locations
     * @return array<string, array<string, mixed>>
     */
    private function fetchWeatherBatch(array $locations): array
    {
        if ($locations === []) {
            return [];
        }

        $response = Http::connectTimeout($this->positiveConfig('connect_timeout_seconds', 2))
            ->timeout($this->positiveConfig('timeout_seconds', 5))
            ->acceptJson()
            ->get(self::WEATHER_URL, [
                'latitude' => implode(',', array_map(static fn (array $location): string => sprintf('%.7F', $location['latitude']), $locations)),
                'longitude' => implode(',', array_map(static fn (array $location): string => sprintf('%.7F', $location['longitude']), $locations)),
                'current' => 'temperature_2m,dew_point_2m,precipitation,precipitation_probability,weather_code,cloud_cover,visibility,wind_speed_10m,wind_speed_80m,wind_speed_120m,wind_direction_120m,wind_gusts_10m',
                'daily' => 'sunrise,sunset',
                'timezone' => self::LOCAL_TIMEZONE,
                'forecast_days' => 1,
            ]);
        if (! $response->successful() || strlen($response->body()) > 1048576) {
            throw new \RuntimeException('Weather provider unavailable.');
        }

        $payload = $response->json();
        $items = count($locations) === 1 && is_array($payload) && array_key_exists('current', $payload)
            ? [$payload]
            : $payload;
        if (! is_array($items) || count($items) !== count($locations) || ! array_is_list($items)) {
            throw new \UnexpectedValueException('Weather batch response has an unexpected location count.');
        }

        $result = [];
        foreach ($locations as $index => $location) {
            $item = $items[$index] ?? null;
            if (! is_array($item)) {
                throw new \UnexpectedValueException('Weather location response invalid.');
            }
            if (count($locations) > 1 && ! $this->responseCoordinatesMatch($item, $location)) {
                throw new \UnexpectedValueException('Weather location order could not be verified.');
            }
            $result[$this->weatherCacheKey($location['latitude'], $location['longitude'])] = $this->parseWeather($item);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function parseWeather(array $payload): array
    {
        $current = $payload['current'] ?? null;
        $daily = $payload['daily'] ?? null;
        if (! is_array($current) || ! is_string($current['time'] ?? null) || ! is_array($daily)) {
            throw new \UnexpectedValueException('Weather response invalid.');
        }
        $measuredAt = $this->weatherTimestamp($current['time']);
        $sunrise = $this->dailyTimestamp($daily['sunrise'] ?? null);
        $sunset = $this->dailyTimestamp($daily['sunset'] ?? null);
        $temperature = $this->requiredBoundedNumber($current['temperature_2m'] ?? null, -80, 60);
        $dewPoint = $this->requiredBoundedNumber($current['dew_point_2m'] ?? null, -100, 60);

        return [
            'weather_code' => $this->requiredInteger($current['weather_code'] ?? null, 0, 99),
            'temperature_c' => $temperature,
            'dew_point_c' => $dewPoint,
            'dew_point_spread_c' => max(0.0, $temperature - $dewPoint),
            'wind_speed_10m_kmh' => $this->requiredBoundedNumber($current['wind_speed_10m'] ?? null, 0, 500),
            'wind_speed_80m_kmh' => $this->requiredBoundedNumber($current['wind_speed_80m'] ?? null, 0, 500),
            'wind_speed_kmh' => $this->requiredBoundedNumber($current['wind_speed_120m'] ?? null, 0, 500),
            'wind_gust_kmh' => $this->requiredBoundedNumber($current['wind_gusts_10m'] ?? null, 0, 500),
            'wind_direction_degrees' => $this->requiredBoundedNumber($current['wind_direction_120m'] ?? null, 0, 360),
            'precipitation_probability_pct' => $this->requiredBoundedNumber($current['precipitation_probability'] ?? null, 0, 100),
            'precipitation_mm' => $this->requiredBoundedNumber($current['precipitation'] ?? null, 0, 500),
            'cloud_cover_pct' => $this->requiredBoundedNumber($current['cloud_cover'] ?? null, 0, 100),
            'visibility_m' => $this->requiredBoundedNumber($current['visibility'] ?? null, 0, 100000),
            'sunrise' => $sunrise->toIso8601String(),
            'sunset' => $sunset->toIso8601String(),
            'measured_at' => $measuredAt->toIso8601String(),
            'refreshed_at' => now()->toIso8601String(),
            'stale' => $this->isStale($measuredAt, $this->positiveConfig('weather_stale_seconds', 1800)),
            'source' => ['name' => 'Open-Meteo', 'url' => 'https://open-meteo.com/en/docs'],
        ];
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @param  array<string, array<string, mixed>>  $readings
     * @return array<string, mixed>
     */
    private function aggregateWeather(array $resolution, array $readings): array
    {
        if (! ($resolution['complete'] ?? false)) {
            return $this->unavailableWeather('De gekozen locatie kon niet volledig server-side worden bepaald.');
        }

        $selected = [];
        foreach ((array) ($resolution['locations'] ?? []) as $location) {
            $reading = $readings[$this->weatherCacheKey($location['latitude'], $location['longitude'])] ?? null;
            if (! is_array($reading) || ! $this->completeWeatherReading($reading)) {
                return $this->unavailableWeather(
                    'Niet voor alle vereiste provincies is een complete actuele of laatst-goede meting beschikbaar.',
                    count($selected),
                );
            }
            $selected[] = $reading;
        }

        if (count($selected) !== (int) ($resolution['expected_locations'] ?? 0)) {
            return $this->unavailableWeather('Het vereiste aantal locatiemetingen is niet compleet.', count($selected));
        }

        $averageKeys = [
            'temperature_c',
            'dew_point_c',
            'dew_point_spread_c',
            'wind_speed_10m_kmh',
            'wind_speed_80m_kmh',
            'wind_speed_kmh',
            'wind_gust_kmh',
            'precipitation_probability_pct',
            'precipitation_mm',
            'cloud_cover_pct',
            'visibility_m',
        ];
        $result = [];
        foreach ($averageKeys as $key) {
            $result[$key] = array_sum(array_column($selected, $key)) / count($selected);
        }
        $result['weather_code'] = $this->representativeWeatherCode(array_map(
            static fn (array $reading): int => (int) $reading['weather_code'],
            $selected,
        ));
        $result['wind_direction_degrees'] = $this->circularMean(array_map(
            static fn (array $reading): float => (float) $reading['wind_direction_degrees'],
            $selected,
        ));
        $measuredTimes = array_map(fn (array $reading): CarbonImmutable => $this->timestamp($reading['measured_at']), $selected);
        $refreshedTimes = array_map(
            fn (array $reading): CarbonImmutable => $this->timestamp((string) ($reading['refreshed_at'] ?? $reading['measured_at'])),
            $selected,
        );
        $sunrises = array_map(fn (array $reading): CarbonImmutable => $this->localTimestamp($reading['sunrise']), $selected);
        $sunsets = array_map(fn (array $reading): CarbonImmutable => $this->localTimestamp($reading['sunset']), $selected);
        usort($measuredTimes, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
        usort($refreshedTimes, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
        usort($sunrises, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
        usort($sunsets, static fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        $stale = collect($selected)->contains(fn (array $reading): bool => (bool) ($reading['stale'] ?? false));
        $result['max_non_red_wind_height_agl_m'] = $this->maxNonRedWindHeight($result, $stale);

        return [
            ...$result,
            'sunrise_earliest' => $sunrises[0]->toIso8601String(),
            'sunrise_latest' => $sunrises[array_key_last($sunrises)]->toIso8601String(),
            'sunset_earliest' => $sunsets[0]->toIso8601String(),
            'sunset_latest' => $sunsets[array_key_last($sunsets)]->toIso8601String(),
            'measured_at' => $measuredTimes[0]->toIso8601String(),
            'refreshed_at' => $refreshedTimes[array_key_last($refreshedTimes)]->toIso8601String(),
            'stale' => $stale,
            'source' => [
                'name' => count($selected) === WallboardForecastLocationService::NETHERLANDS_PROVINCE_COUNT
                    ? 'Open-Meteo (12 provincies)'
                    : 'Open-Meteo',
                'url' => 'https://open-meteo.com/en/docs',
            ],
            'sample_count' => count($selected),
            'complete' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableWeather(string $note, int $sampleCount = 0): array
    {
        return [
            'stale' => false,
            'source' => ['name' => 'Open-Meteo', 'url' => 'https://open-meteo.com/en/docs'],
            'measured_at' => null,
            'refreshed_at' => null,
            'sample_count' => $sampleCount,
            'complete' => false,
            'availability_note' => $note,
        ];
    }

    /** @return array<string, mixed> */
    private function daylight(array $weather): array
    {
        return [
            'timezone' => self::LOCAL_TIMEZONE,
            'sunrise_earliest' => $weather['sunrise_earliest'] ?? null,
            'sunrise_latest' => $weather['sunrise_latest'] ?? null,
            'sunset_earliest' => $weather['sunset_earliest'] ?? null,
            'sunset_latest' => $weather['sunset_latest'] ?? null,
            'stale' => (bool) ($weather['stale'] ?? false),
            'source' => $weather['source'] ?? ['name' => 'Onbekend', 'url' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function kpReading(): array
    {
        return $this->cachedReading(self::CACHE_NAMESPACE.':kp', function (): array {
            $primary = $this->fetchKpCandidate(self::KP_CURRENT_URL, ['estimated_kp', 'kp_index']);
            if ($primary !== null && ! $this->isStale($primary['time'], $this->positiveConfig('kp_stale_seconds', 14400))) {
                return $this->kpPayload($primary, 'NOAA SWPC Kp (1 minuut)', self::KP_CURRENT_URL);
            }

            $fallback = $this->fetchKpCandidate(self::KP_FALLBACK_URL, ['Kp']);
            $latest = $this->newestKpCandidate($primary, $fallback);
            if ($latest === null) {
                throw new \UnexpectedValueException('NOAA SWPC bevat geen geldige Kp-waarneming.');
            }

            return $this->kpPayload(
                $latest,
                $latest === $fallback ? 'NOAA SWPC Kp (3 uur)' : 'NOAA SWPC Kp (1 minuut)',
                $latest === $fallback ? self::KP_FALLBACK_URL : self::KP_CURRENT_URL,
            );
        }, 'NOAA SWPC leverde geen valide actuele Kp-waarneming via de 1-minuut- of 3-uursfeed.');
    }

    /**
     * @param  list<string>  $valueFields
     * @return array{time: CarbonImmutable, value: float}|null
     */
    private function fetchKpCandidate(string $url, array $valueFields): ?array
    {
        try {
            $response = Http::connectTimeout($this->positiveConfig('connect_timeout_seconds', 2))
                ->timeout($this->positiveConfig('timeout_seconds', 5))
                ->acceptJson()
                ->get($url);
            if (! $response->successful() || strlen($response->body()) > 524288) {
                return null;
            }
            $payload = $response->json();
            if (! is_array($payload)) {
                return null;
            }

            $latest = null;
            foreach ($payload as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $time = $row['time_tag'] ?? null;
                $value = null;
                foreach ($valueFields as $field) {
                    $candidate = $row[$field] ?? null;
                    if (! is_numeric($candidate) || ! is_finite((float) $candidate)) {
                        continue;
                    }
                    $number = (float) $candidate;
                    if ($number < 0 || $number > 9) {
                        continue;
                    }
                    $value = $number;
                    break;
                }
                if ($time === null && array_is_list($row)) {
                    $time = $row[0] ?? null;
                    $value = is_numeric($row[1] ?? null) ? (float) $row[1] : $value;
                }
                if (! is_string($time) || $value === null || $value < 0 || $value > 9) {
                    continue;
                }
                try {
                    $measuredAt = $this->timestamp($time);
                } catch (Throwable) {
                    continue;
                }
                if ($measuredAt->greaterThan(now()->addMinutes(10))) {
                    continue;
                }
                if ($latest === null || $measuredAt->greaterThan($latest['time'])) {
                    $latest = ['time' => $measuredAt, 'value' => $value];
                }
            }

            return $latest;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array{time: CarbonImmutable, value: float} $candidate
     * @return array<string, mixed>
     */
    private function kpPayload(array $candidate, string $name, string $url): array
    {
        return [
            'value' => $candidate['value'],
            'measured_at' => $candidate['time']->toIso8601String(),
            'refreshed_at' => now()->toIso8601String(),
            'stale' => $this->isStale($candidate['time'], $this->positiveConfig('kp_stale_seconds', 14400)),
            'source' => ['name' => $name, 'url' => $url],
        ];
    }

    /**
     * @param  array{time: CarbonImmutable, value: float}|null  $first
     * @param  array{time: CarbonImmutable, value: float}|null  $second
     * @return array{time: CarbonImmutable, value: float}|null
     */
    private function newestKpCandidate(?array $first, ?array $second): ?array
    {
        if ($first === null) {
            return $second;
        }
        if ($second === null) {
            return $first;
        }

        return $second['time']->greaterThan($first['time']) ? $second : $first;
    }

    /**
     * @param  callable(): array<string, mixed>  $loader
     * @return array<string, mixed>
     */
    private function cachedReading(string $key, callable $loader, string $failureNote = 'De externe bron is niet bereikbaar of gaf ongeldige data terug.'): array
    {
        $fresh = Cache::get($key.':fresh');
        if (is_array($fresh)) {
            return $fresh;
        }

        try {
            $reading = $loader();
            $this->storeReading($key, $reading);

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
                'availability_note' => $failureNote,
            ];
        }
    }

    /** @param array<string, mixed> $reading */
    private function storeReading(string $key, array $reading): void
    {
        Cache::put($key.':fresh', $reading, $this->positiveConfig('cache_seconds', 900));
        Cache::put($key.':last-good', $reading, $this->positiveConfig('last_good_cache_seconds', 21600));
    }

    /** @param array<string, mixed> $reading */
    private function completeWeatherReading(array $reading): bool
    {
        foreach (self::REQUIRED_WEATHER_FIELDS as $field) {
            if (in_array($field, ['sunrise', 'sunset'], true)) {
                if (! is_string($reading[$field] ?? null)) {
                    return false;
                }
            } elseif (! is_numeric($reading[$field] ?? null)) {
                return false;
            }
        }

        return is_string($reading['measured_at'] ?? null);
    }

    /** @param list<int> $codes */
    private function representativeWeatherCode(array $codes): int
    {
        $counts = array_count_values($codes);
        $maximum = max($counts);
        $candidates = array_map('intval', array_keys(array_filter($counts, static fn (int $count): bool => $count === $maximum)));
        usort($candidates, fn (int $a, int $b): int => $this->classifier->weatherCodeRisk($b) <=> $this->classifier->weatherCodeRisk($a));

        return $candidates[0];
    }

    /** @param list<float> $degrees */
    private function circularMean(array $degrees): ?float
    {
        $x = 0.0;
        $y = 0.0;
        foreach ($degrees as $degree) {
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

    /** @param array<string, mixed> $weather */
    private function maxNonRedWindHeight(array $weather, bool $stale): ?int
    {
        $samples = [
            10 => $weather['wind_speed_10m_kmh'] ?? null,
            80 => $weather['wind_speed_80m_kmh'] ?? null,
            120 => $weather['wind_speed_kmh'] ?? null,
        ];
        foreach ($samples as $value) {
            if (! is_numeric($value)) {
                return null;
            }
        }
        $maximum = null;
        foreach ($samples as $height => $value) {
            $status = $this->classifier->classify('wind_speed_kmh', (float) $value, $stale)['status'];
            if (in_array($status, [WallboardForecastClassifier::STATUS_GREEN, WallboardForecastClassifier::STATUS_ORANGE], true)) {
                $maximum = $height;
            }
        }

        return $maximum;
    }

    private function roundedOrNull(mixed $value, int $precision): ?float
    {
        return is_numeric($value) && is_finite((float) $value)
            ? round((float) $value, $precision)
            : null;
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float}>  $locations
     * @return array{latitude: float|null, longitude: float|null}
     */
    private function centre(array $locations): array
    {
        if ($locations === []) {
            return ['latitude' => null, 'longitude' => null];
        }

        return [
            'latitude' => round(array_sum(array_column($locations, 'latitude')) / count($locations), 7),
            'longitude' => round(array_sum(array_column($locations, 'longitude')) / count($locations), 7),
        ];
    }

    /** @param array<string, mixed> $response
     * @param  array{latitude: float, longitude: float}  $requested
     */
    private function responseCoordinatesMatch(array $response, array $requested): bool
    {
        return is_numeric($response['latitude'] ?? null)
            && is_numeric($response['longitude'] ?? null)
            && abs((float) $response['latitude'] - $requested['latitude']) <= 0.25
            && abs((float) $response['longitude'] - $requested['longitude']) <= 0.25;
    }

    private function weatherCacheKey(float $latitude, float $longitude): string
    {
        return self::CACHE_NAMESPACE.':weather:'.sha1(sprintf('%.5F,%.5F', $latitude, $longitude));
    }

    private function dailyTimestamp(mixed $values): CarbonImmutable
    {
        if (! is_array($values) || ! is_string($values[0] ?? null)) {
            throw new \UnexpectedValueException('Daily weather time invalid.');
        }

        return $this->localTimestamp($values[0]);
    }

    private function localTimestamp(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, self::LOCAL_TIMEZONE)
            ->setTimezone(self::LOCAL_TIMEZONE);
    }

    private function weatherTimestamp(string $value): CarbonImmutable
    {
        return $this->localTimestamp($value);
    }

    private function timestamp(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    /** @param array<string, mixed> $weather
     * @param  array<string, mixed>  $kp
     */
    private function forecastGeneratedAt(array $weather, array $kp): string
    {
        $latest = null;
        foreach ([$weather['refreshed_at'] ?? null, $kp['refreshed_at'] ?? null] as $value) {
            if (! is_string($value)) {
                continue;
            }
            try {
                $candidate = $this->timestamp($value);
            } catch (Throwable) {
                continue;
            }
            if ($latest === null || $candidate->greaterThan($latest)) {
                $latest = $candidate;
            }
        }

        return ($latest ?? CarbonImmutable::now())->toIso8601String();
    }

    private function isStale(CarbonImmutable $measuredAt, int $maximumAgeSeconds): bool
    {
        return $measuredAt->greaterThan(now()->addMinutes(10))
            || $measuredAt->lessThan(now()->subSeconds($maximumAgeSeconds));
    }

    private function requiredBoundedNumber(mixed $value, float $minimum, float $maximum): float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new \UnexpectedValueException('Weather value is not numeric.');
        }
        $number = (float) $value;
        if ($number < $minimum || $number > $maximum) {
            throw new \UnexpectedValueException('Weather value is outside its allowed range.');
        }

        return $number;
    }

    private function requiredInteger(mixed $value, int $minimum, int $maximum): int
    {
        $number = $this->requiredBoundedNumber($value, $minimum, $maximum);
        if (floor($number) !== $number) {
            throw new \UnexpectedValueException('Weather code must be an integer.');
        }

        return (int) $number;
    }

    private function positiveConfig(string $key, int $fallback): int
    {
        $value = config("dis.wallboards.uav_forecast.{$key}", $fallback);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}
