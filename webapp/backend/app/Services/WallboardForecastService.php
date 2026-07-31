<?php

namespace App\Services;

use App\Contracts\GnssForecastProvider;
use App\Contracts\UavWeatherForecastProvider;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WallboardForecastService
{
    private const LOCAL_TIMEZONE = 'Europe/Amsterdam';

    private const CACHE_NAMESPACE = 'wallboard:uav-forecast:v5';

    private const KP_CURRENT_URL = 'https://services.swpc.noaa.gov/json/planetary_k_index_1m.json';

    private const KP_FALLBACK_URL = 'https://services.swpc.noaa.gov/products/noaa-planetary-k-index.json';

    public function __construct(
        private readonly WallboardForecastClassifier $classifier,
        private readonly WallboardForecastLocationService $locations,
        private readonly UavWeatherForecastProvider $weatherForecasts,
        private readonly GnssForecastProvider $gnssForecasts,
        private readonly KnmiCloudBaseObservationService $cloudBaseObservations,
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

        $optionsByPageId = [];
        foreach ($pages as $page) {
            $pageId = (string) ($page['id'] ?? '');
            if ($pageId !== '') {
                $optionsByPageId[$pageId] = (array) ($page['options'] ?? []);
            }
        }

        return $this->forecastsForOptions($optionsByPageId);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forecastForOptions(array $options): array
    {
        return $this->forecastsForOptions(['forecast' => $options])['forecast'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $optionsByPageId
     * @return array<string, array<string, mixed>>
     */
    private function forecastsForOptions(array $optionsByPageId): array
    {
        if ($optionsByPageId === []) {
            return [];
        }

        $resolvedPages = [];
        foreach ($optionsByPageId as $pageId => $options) {
            $resolution = $this->locations->resolve($options);
            $resolvedPages[$pageId] = ['options' => $options, 'resolution' => $resolution];
        }

        $kp = $this->kpReading();
        $result = [];

        foreach ($resolvedPages as $pageId => ['options' => $options, 'resolution' => $resolution]) {
            $weather = $this->weatherForecasts->forResolution($resolution);
            $gnss = $this->gnssForecasts->forResolution($resolution);
            [$gnssVisibleMetric, $gnssUsableMetric] = $this->gnssMetrics($gnss);
            $cloudForecast = $weather;
            $condition = $this->condition($weather);
            $windReferenceHeight = $this->windReferenceHeight($weather);
            $windMetric = $this->metric(
                'wind_speed_kmh',
                "Wind op {$windReferenceHeight} m AGL",
                $weather['wind_speed_kmh'] ?? null,
                'km/u',
                $weather,
                1,
            );
            $windMetric['height_samples_agl_m'] = $this->windSamples($weather);
            $windMetric['max_non_red_wind_height_agl_m'] = $this->maxNonRedWindHeight(
                $weather,
                (bool) ($weather['stale'] ?? false),
            );
            $totalCloudMetric = $this->metric(
                'cloud_cover_pct',
                'Totale modelbewolking',
                $cloudForecast['cloud_cover_pct'] ?? null,
                '%',
                $cloudForecast,
                0,
            );
            $lowCloudMetric = $this->metric(
                'low_cloud_cover_pct',
                'Lage bewolking',
                $cloudForecast['cloud_cover_low_pct'] ?? null,
                '%',
                $cloudForecast,
                0,
            );
            $dwdLowCloudBand = $this->dwdLowCloudBand($cloudForecast);
            $lowCloudMetric['cloud_cover_below_500ft_pct'] = $dwdLowCloudBand['below_500ft_pct'] ?? null;
            if ($dwdLowCloudBand !== null) {
                $lowCloudMetric['source'] = $dwdLowCloudBand['source'];
                $lowCloudMetric['measured_at'] = $dwdLowCloudBand['valid_at'];
                $lowCloudMetric['source_height_label'] = 'DWD MOSMIX_L Nl (onder 2 km) en N05 (onder 500 ft); geen exacte wolkenbasis afgeleid';
            }
            $cloudLayers = [
                'low_pct' => $this->roundedOrNull($cloudForecast['cloud_cover_low_pct'] ?? null, 0),
                'mid_pct' => $this->roundedOrNull($cloudForecast['cloud_cover_mid_pct'] ?? null, 0),
                'high_pct' => $this->roundedOrNull($cloudForecast['cloud_cover_high_pct'] ?? null, 0),
                'total_pct' => $this->roundedOrNull($cloudForecast['cloud_cover_pct'] ?? null, 0),
            ];
            $lowCloudMetric['cloud_layers'] = ($cloudForecast['complete'] ?? false) === true
                && ! in_array(null, $cloudLayers, true)
                ? [
                    'low_pct' => $cloudLayers['low_pct'],
                    'mid_pct' => $cloudLayers['mid_pct'],
                    'high_pct' => $cloudLayers['high_pct'],
                    'total_pct' => $cloudLayers['total_pct'],
                ]
                : null;
            $cloudBaseForecast = $this->cloudBaseForecast($cloudForecast);
            $lowCloudMetric['cloud_base_forecast'] = $cloudBaseForecast;
            $lowCloudMetric['cloud_base_observation'] = $this->cloudBaseObservations->forResolution($resolution);
            if ($cloudBaseForecast['status'] !== 'forecast') {
                if ($dwdLowCloudBand !== null) {
                    // MOSMIX N05 evaluates the operationally relevant band
                    // below 500 ft directly. It is not converted into a fake
                    // cloud-base height; both real cover percentages retain
                    // the configured low-cloud classification.
                    $below500Classification = $this->classifier->classify(
                        'low_cloud_cover_pct',
                        $dwdLowCloudBand['below_500ft_pct'],
                        false,
                    );
                    $lowCloudMetric['status'] = $this->classifier->overall([
                        ['status' => $lowCloudMetric['status']],
                        $below500Classification,
                    ]);
                    $lowCloudMetric['explanation'] .= sprintf(
                        ' DWD N05 meldt %.0f%% bewolking onder 500 ft; DIS leidt hier geen exacte wolkenbasishoogte uit af.',
                        $dwdLowCloudBand['below_500ft_pct'],
                    );
                } else {
                    // A missing cloud base must prevent a reassuring green
                    // result, but never hide a known orange/red hazard.
                    if ($lowCloudMetric['status'] === WallboardForecastClassifier::STATUS_GREEN) {
                        $lowCloudMetric['status'] = WallboardForecastClassifier::STATUS_UNKNOWN;
                    }
                    $lowCloudMetric['explanation'] .= ' De modelwolkenbasis is niet volledig en actueel beschikbaar.';
                }
            }
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
                $this->metric(
                    'wind_direction_degrees',
                    "Windrichting op {$windReferenceHeight} m AGL",
                    $weather['wind_direction_degrees'] ?? null,
                    '°',
                    $weather,
                    0,
                ),
                $this->metric(
                    'precipitation_probability_pct',
                    'Neerslagkans',
                    $weather['precipitation_probability_pct'] ?? null,
                    '%',
                    $weather,
                    0,
                ),
                $this->metric('precipitation_mm', 'Modelneerslag +1 uur', $weather['precipitation_mm'] ?? null, 'mm', $weather, 1),
                $this->precipitationOutlookMetric($weather),
                $this->thunderstormOutlookMetric($weather, (int) ($resolution['expected_locations'] ?? 0)),
                $totalCloudMetric,
                $lowCloudMetric,
                $this->metric('visibility_m', 'Zicht', $weather['visibility_m'] ?? null, 'm', $weather, 0),
                $this->metric('kp_index', 'Geomagnetische activiteit', $kp['value'] ?? null, 'Kp', $kp, 2),
                $gnssVisibleMetric,
                $gnssUsableMetric,
            ];
            $adviceMetrics = array_values(array_filter(
                $metrics,
                // Total cloud cover is context for the separate low-cloud card.
                // The forward-looking rain and thunder cards are operational
                // safety inputs and therefore remain part of the advice even
                // when an administrator hides them from the visual grid.
                static fn (array $metric): bool => ! in_array(
                    $metric['key'] ?? null,
                    ['cloud_cover_pct', 'gnss_satellites', 'gnss_satellites_fix'],
                    true,
                ),
            ));

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
                'visible_blocks' => array_values((array) ($options['visible_blocks'] ?? WallboardConfiguration::DEFAULT_FORECAST_VISIBLE_BLOCKS)),
                'overall_status' => $this->classifier->overall($adviceMetrics),
                'generated_at' => $this->forecastGeneratedAt($weather, $kp, $cloudForecast, $gnss),
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
                    ? $this->nationalScopeNote($weather)
                    : $this->addressScopeNote($weather),
                'disclaimer' => $this->disclaimer($weather),
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

        $windReferenceHeight = $this->windReferenceHeight($reading);
        $isDwdFallback = $this->isDwdFallback($reading);
        $height = match ($key) {
            'wind_speed_kmh', 'wind_direction_degrees' => [
                'altitude_m' => $windReferenceHeight,
                'source_height_label' => "{$windReferenceHeight} m boven maaiveld",
            ],
            'wind_gust_kmh' => ['altitude_m' => 10, 'source_height_label' => '10 m boven maaiveld (grondwaarde)'],
            'temperature_c', 'dew_point_c' => ['altitude_m' => 2, 'source_height_label' => '2 m boven maaiveld (grondwaarde)'],
            'cloud_cover_pct' => [
                'altitude_m' => null,
                'source_height_label' => $isDwdFallback
                    ? 'Volledige hemelkolom volgens DWD MOSMIX'
                    : 'Volledige hemelkolom volgens DMI HARMONIE DINI',
            ],
            'low_cloud_cover_pct' => [
                'altitude_m' => null,
                'source_height_label' => $isDwdFallback
                    ? 'DWD MOSMIX levert via deze live fallback geen afzonderlijke lage wolkenlaag'
                    : 'DMI HARMONIE DINI-categorie lage bewolking; geen vaste hoogteband',
            ],
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

    /**
     * Convert calculated BKG/IGS open-sky geometry into the two legacy GNSS
     * card keys. The second key keeps its wire name for backwards
     * compatibility, but deliberately no longer claims receiver “in fix”
     * telemetry.
     *
     * @param  array<string, mixed>  $reading
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function gnssMetrics(array $reading): array
    {
        $counts = is_array($reading['counts'] ?? null) ? $reading['counts'] : [];
        $visibleByConstellation = is_array($counts['visible_by_constellation'] ?? null)
            ? $counts['visible_by_constellation']
            : [];
        $usableByConstellation = is_array($counts['usable_by_constellation'] ?? null)
            ? $counts['usable_by_constellation']
            : [];
        $pdop = is_array($reading['pdop'] ?? null) ? $reading['pdop'] : [];
        $visible = $this->boundedGnssCount($counts['visible'] ?? null);
        $usable = $this->boundedGnssCount($counts['usable'] ?? null);
        $visibleGps = $this->boundedGnssCount($visibleByConstellation['gps'] ?? null);
        $visibleGalileo = $this->boundedGnssCount($visibleByConstellation['galileo'] ?? null);
        $usableGps = $this->boundedGnssCount($usableByConstellation['gps'] ?? null);
        $usableGalileo = $this->boundedGnssCount($usableByConstellation['galileo'] ?? null);
        $pdopValue = is_numeric($pdop['value'] ?? null) && is_finite((float) $pdop['value'])
            ? round((float) $pdop['value'], 2)
            : null;
        $pdopComplete = ($pdop['complete'] ?? false) === true;
        $geometrySufficient = is_bool($pdop['geometry_sufficient'] ?? null)
            ? $pdop['geometry_sufficient']
            : $pdopValue !== null;
        $locationCount = is_int($reading['location_count'] ?? null)
            ? $reading['location_count']
            : null;
        $mask = is_numeric($reading['elevation_mask_deg'] ?? null)
            && is_finite((float) $reading['elevation_mask_deg'])
            ? round((float) $reading['elevation_mask_deg'], 1)
            : null;
        $measuredAt = is_string($reading['measured_at'] ?? null) ? $reading['measured_at'] : null;
        $source = $this->gnssSource($reading['source'] ?? null);

        $timestampIsFresh = false;
        if ($measuredAt !== null) {
            try {
                $timestampIsFresh = ! $this->isStale($this->timestamp($measuredAt), 900);
            } catch (Throwable) {
                $timestampIsFresh = false;
            }
        }

        $complete = ($reading['complete'] ?? false) === true
            && ($reading['stale'] ?? true) === false
            && $timestampIsFresh
            && $visible !== null
            && $usable !== null
            && $visibleGps !== null
            && $visibleGalileo !== null
            && $usableGps !== null
            && $usableGalileo !== null
            && $visible === $visibleGps + $visibleGalileo
            && $usable === $usableGps + $usableGalileo
            && $usable <= $visible
            && $pdopComplete
            && ($geometrySufficient
                ? $pdopValue !== null && $pdopValue > 0 && $pdopValue <= 100
                : $pdopValue === null)
            && is_int($pdop['sample_count'] ?? null)
            && $locationCount !== null
            && $locationCount >= 1
            && $locationCount <= WallboardForecastLocationService::NETHERLANDS_PROVINCE_COUNT
            && $pdop['sample_count'] === $locationCount
            && $mask !== null
            && $mask >= 0
            && $mask <= 30
            && $source['name'] !== 'Onbekend';
        $classification = ! $complete
            ? $this->classifier->classify('gnss_pdop', null, true)
            : ($geometrySufficient
                ? $this->classifier->classify('gnss_pdop', $pdopValue)
                : [
                    'status' => WallboardForecastClassifier::STATUS_RED,
                    'explanation' => 'Rood: de berekende GPS/Galileo-geometrie heeft onvoldoende rang voor een volledige positie- en klokoplossing.',
                ]);
        $maskLabel = $mask === null
            ? 'het ingestelde masker'
            : rtrim(rtrim(number_format($mask, 1, ',', ''), '0'), ',').'°';
        $caveat = ' Berekend uit GPS- en Galileo-broadcastbanen voor open hemel. Gebouwen, bomen, multipath, lokale storing en de ontvanger van de drone worden niet gemeten; dit is geen receiver-fix.';
        $availabilityNote = is_string($reading['availability_note'] ?? null)
            ? ' '.trim($reading['availability_note'])
            : '';
        $visibleDetail = $complete
            ? "GPS {$visibleGps} · Galileo {$visibleGalileo} · open-sky boven horizon"
            : null;
        $usableDetail = $complete
            ? sprintf(
                'GPS %d · Galileo %d · %s · elevatiemasker %s',
                $usableGps,
                $usableGalileo,
                $geometrySufficient
                    ? 'PDOP '.number_format($pdopValue, 2, ',', '')
                    : 'PDOP niet berekenbaar: onvoldoende geometrische rang',
                $maskLabel,
            )
            : null;

        return [
            [
                'key' => 'gnss_satellites',
                'label' => 'Berekende GNSS-satellieten boven horizon',
                'value' => $complete ? $visible : null,
                'unit' => 'satellieten',
                'display_value' => null,
                'display_unit' => null,
                'status' => $classification['status'],
                'stale' => (bool) ($reading['stale'] ?? false),
                'source' => $source,
                'measured_at' => $complete ? $measuredAt : null,
                'explanation' => $complete
                    ? $classification['explanation'].$caveat
                    : 'De actuele GNSS-open-skyberekening is niet volledig beschikbaar.'.$availabilityNote,
                'altitude_m' => null,
                'source_height_label' => $visibleDetail,
            ],
            [
                'key' => 'gnss_satellites_fix',
                'label' => $mask === null
                    ? 'Berekende GNSS-satellieten boven elevatiemasker'
                    : "Berekende GNSS-satellieten boven {$maskLabel}",
                'value' => $complete ? $usable : null,
                'unit' => 'satellieten',
                'display_value' => null,
                'display_unit' => null,
                'status' => $classification['status'],
                'stale' => (bool) ($reading['stale'] ?? false),
                'source' => $source,
                'measured_at' => $complete ? $measuredAt : null,
                'explanation' => $complete
                    ? $classification['explanation'].$caveat
                    : 'De actuele GNSS-geometrie en PDOP zijn niet volledig beschikbaar.'.$availabilityNote,
                'altitude_m' => null,
                'source_height_label' => $usableDetail,
            ],
        ];
    }

    private function boundedGnssCount(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= 100 ? $value : null;
    }

    /** @return array<string, mixed> */
    private function gnssSource(mixed $value): array
    {
        if (! is_array($value) || ! is_string($value['name'] ?? null) || trim($value['name']) === '') {
            return ['name' => 'Onbekend', 'url' => null];
        }

        $termsUrl = is_string($value['terms_url'] ?? null) ? $value['terms_url'] : null;

        return [
            'name' => trim($value['name']),
            'url' => is_string($value['url'] ?? null) ? $value['url'] : null,
            'license' => 'IGS Terms of Use',
            'license_url' => $termsUrl,
            'attribution' => is_string($value['attribution'] ?? null)
                ? $value['attribution']
                : 'BKG / International GNSS Service (IGS)',
            'modified' => true,
            'processed_by' => 'DIS',
            'processing_note' => 'GPS + Galileo broadcast-ephemeriden · open-skyberekening',
        ];
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

    /** @param array<string, mixed> $reading
     * @return array<string, mixed>
     */
    private function precipitationOutlookMetric(array $reading): array
    {
        $complete = ($reading['complete'] ?? false) === true
            && is_numeric($reading['forecast_precipitation_peak_mm_h'] ?? null)
            && is_string($reading['forecast_precipitation_until'] ?? null)
            && is_string($reading['valid_at'] ?? null)
            && is_int($reading['sample_count'] ?? null)
            && is_int($reading['expected_sample_count'] ?? null)
            && $reading['sample_count'] === $reading['expected_sample_count'];
        $stale = (bool) ($reading['stale'] ?? false);
        $peak = $complete ? round((float) $reading['forecast_precipitation_peak_mm_h'], 2) : null;
        $rateClassification = $this->classifier->classify('precipitation_rate_mm_h', $peak, $stale);
        $status = $complete ? $rateClassification['status'] : WallboardForecastClassifier::STATUS_UNKNOWN;
        $thirdHourProbability = $this->roundedOrNull(
            $reading['forecast_precipitation_third_hour_probability_pct'] ?? null,
            0,
        );
        if ($thirdHourProbability !== null
            && ($thirdHourProbability < 0 || $thirdHourProbability > 100)) {
            $thirdHourProbability = null;
        }
        $thirdHourFrom = is_string($reading['forecast_precipitation_third_hour_from'] ?? null)
            ? $reading['forecast_precipitation_third_hour_from']
            : null;
        $forecastUntil = is_string($reading['forecast_precipitation_until'] ?? null)
            ? $reading['forecast_precipitation_until']
            : null;
        $probabilityComplete = $complete
            && $thirdHourProbability !== null
            && $thirdHourFrom !== null
            && $forecastUntil !== null;
        $probabilityClassification = $this->classifier->classify(
            'precipitation_probability_pct',
            $probabilityComplete ? $thirdHourProbability : null,
            $stale,
        );
        $availabilityNote = is_string($reading['availability_note'] ?? null)
            ? ' '.$reading['availability_note']
            : '';
        $isDwdFallback = $this->isDwdFallback($reading);
        $modelName = $isDwdFallback ? 'DWD MOSMIX' : 'DMI HARMONIE DINI';

        return [
            'key' => 'precipitation_outlook',
            'label' => 'Modelneerslag +3 uur',
            'value' => $peak,
            'unit' => 'mm/u',
            'display_value' => null,
            'display_unit' => null,
            'status' => $status,
            'stale' => $stale,
            'source' => $reading['source'] ?? ['name' => 'DMI HARMONIE DINI', 'url' => null],
            'measured_at' => $complete ? $reading['valid_at'] : null,
            'explanation' => $complete
                ? ($isDwdFallback
                    ? 'DWD MOSMIX-modelverwachting voor de komende drie uur via Bright Sky; dit is geen live radarmeting.'
                    : 'Deterministische DMI-modelverwachting voor de komende drie uur; dit is geen live radarmeting en bevat geen verzonnen neerslagkans.')
                : "De {$modelName}-modelneerslag voor de komende drie uur is niet compleet beschikbaar.".$availabilityNote,
            'altitude_m' => null,
            'source_height_label' => null,
            // Legacy field names stay additive-compatible until every client has
            // migrated; attribution and explanation identify the model source.
            'precipitation_outlook' => $complete ? [
                'radar_peak_mm_h' => $peak,
                'radar_status' => $rateClassification['status'],
                'radar_first_precipitation_at' => is_string($reading['forecast_precipitation_first_at'] ?? null)
                    ? $reading['forecast_precipitation_first_at']
                    : null,
                'radar_until' => $reading['forecast_precipitation_until'],
                'third_hour_probability_pct' => $probabilityComplete ? $thirdHourProbability : null,
                'third_hour_probability_status' => $probabilityComplete
                    ? $probabilityClassification['status']
                    : WallboardForecastClassifier::STATUS_UNKNOWN,
                'third_hour_from' => $probabilityComplete ? $thirdHourFrom : null,
                'forecast_until' => $probabilityComplete ? $forecastUntil : null,
                'reference_time' => $reading['valid_at'],
                'sample_count' => $reading['sample_count'],
                'expected_sample_count' => $reading['expected_sample_count'],
                'attribution' => $this->structuredAttribution($reading),
            ] : null,
        ];
    }

    /** @param array<string, mixed> $weather
     * @return array<string, mixed>
     */
    private function thunderstormOutlookMetric(array $weather, int $expectedSampleCount): array
    {
        $expected = is_bool($weather['thunderstorm_expected'] ?? null)
            ? $weather['thunderstorm_expected']
            : null;
        $forecastUntil = is_string($weather['thunderstorm_forecast_until'] ?? null)
            ? $weather['thunderstorm_forecast_until']
            : null;
        $sampleCount = (int) ($weather['sample_count'] ?? 0);
        $complete = $expected !== null
            && $forecastUntil !== null
            && $expectedSampleCount > 0
            && $sampleCount === $expectedSampleCount;
        $stale = (bool) ($weather['stale'] ?? false);
        $status = ! $complete || $stale
            ? WallboardForecastClassifier::STATUS_UNKNOWN
            : ($expected
                ? WallboardForecastClassifier::STATUS_RED
                : WallboardForecastClassifier::STATUS_GREEN);

        return [
            'key' => 'thunderstorm_forecast',
            'label' => 'Onweer +3 uur',
            'value' => $complete ? ($expected ? 1 : 0) : null,
            'unit' => null,
            'display_value' => null,
            'display_unit' => null,
            'status' => $status,
            'stale' => $stale,
            'source' => $weather['source'] ?? ['name' => 'DMI HARMONIE DINI', 'url' => null],
            'measured_at' => is_string($weather['measured_at'] ?? null) ? $weather['measured_at'] : null,
            'explanation' => $complete
                ? ($this->isDwdFallback($weather)
                    ? 'DWD MOSMIX-verwachting voor drie uur via Bright Sky op basis van de afgeleide weersconditie. Dit is geen live bliksemdetectie.'
                    : 'DMI-modelverwachting voor drie uur op basis van de modelkans op bliksem. Dit is geen live bliksemdetectie.')
                : 'Er is geen complete, actuele onweersverwachting voor circa drie uur beschikbaar.',
            'altitude_m' => null,
            'source_height_label' => null,
            'thunderstorm_outlook' => $complete ? [
                'expected' => $expected,
                'first_expected_at' => is_string($weather['thunderstorm_first_expected_at'] ?? null)
                    ? $weather['thunderstorm_first_expected_at']
                    : null,
                'forecast_until' => $forecastUntil,
                'sample_count' => $sampleCount,
                'expected_sample_count' => $expectedSampleCount,
                'attribution' => $this->structuredAttribution($weather),
            ] : null,
        ];
    }

    /**
     * Keep the DMI model ceiling separate from measured EDR station layers.
     * DMI documents the value in metres but not as MSL or AGL, so the API
     * deliberately does not imply either height reference.
     *
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>
     */
    private function cloudBaseForecast(array $reading): array
    {
        $sampleCount = is_int($reading['cloud_base_sample_count'] ?? null)
            ? $reading['cloud_base_sample_count']
            : 0;
        $expectedSampleCount = is_int($reading['cloud_base_expected_sample_count'] ?? null)
            ? $reading['cloud_base_expected_sample_count']
            : 0;
        $complete = ($reading['complete'] ?? false) === true
            && ($reading['cloud_base_complete'] ?? false) === true
            && ! (bool) ($reading['stale'] ?? false)
            && $expectedSampleCount > 0
            && $sampleCount === $expectedSampleCount;
        $height = $this->roundedOrNull($reading['cloud_base_m'] ?? null, 0);
        $isDwdFallback = $this->isDwdFallback($reading);

        return [
            'status' => $complete && $height !== null ? 'forecast' : 'unknown',
            'base_height_m' => $complete ? $height : null,
            'height_reference' => 'model_unspecified',
            'aggregation' => ! $isDwdFallback && is_string($reading['cloud_base_aggregation'] ?? null)
                ? $reading['cloud_base_aggregation']
                : null,
            'sample_count' => $sampleCount,
            'expected_sample_count' => $expectedSampleCount,
            'model_run_at' => ! $isDwdFallback && is_string($reading['model_run_at'] ?? null)
                ? $reading['model_run_at']
                : null,
            'valid_at' => ! $isDwdFallback && is_string($reading['valid_at'] ?? null)
                ? $reading['valid_at']
                : null,
            'attribution' => $isDwdFallback ? 'DWD_MOSMIX' : 'DMI_HARMONIE',
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

    /** @param array<string, mixed> $weather */
    private function maxNonRedWindHeight(array $weather, bool $stale): ?int
    {
        $maximum = null;
        foreach ($this->windSamples($weather) as $sample) {
            $value = $sample['speed_kmh'];
            if ($value === null) {
                return null;
            }
            $status = $this->classifier->classify('wind_speed_kmh', $value, $stale)['status'];
            if (in_array($status, [WallboardForecastClassifier::STATUS_GREEN, WallboardForecastClassifier::STATUS_ORANGE], true)) {
                $maximum = $sample['height_agl_m'];
            }
        }

        return $maximum;
    }

    /**
     * @param  array<string, mixed>  $weather
     * @return list<array{height_agl_m: int, speed_kmh: float|null}>
     */
    private function windSamples(array $weather): array
    {
        if (! $this->isDwdFallback($weather)) {
            return [
                ['height_agl_m' => 10, 'speed_kmh' => $this->roundedOrNull($weather['wind_speed_10m_kmh'] ?? null, 1)],
                ['height_agl_m' => 100, 'speed_kmh' => $this->roundedOrNull($weather['wind_speed_100m_kmh'] ?? null, 1)],
                ['height_agl_m' => 150, 'speed_kmh' => $this->roundedOrNull($weather['wind_speed_150m_kmh'] ?? null, 1)],
            ];
        }

        $speed = $this->roundedOrNull($weather['wind_speed_10m_kmh'] ?? null, 1)
            ?? $this->roundedOrNull($weather['wind_speed_kmh'] ?? null, 1);
        if ($speed === null) {
            return [];
        }

        return [['height_agl_m' => 10, 'speed_kmh' => $speed]];
    }

    /** @param array<string, mixed> $reading */
    private function windReferenceHeight(array $reading): int
    {
        if ($this->isDwdFallback($reading)) {
            return 10;
        }

        $height = $reading['wind_reference_height_agl_m'] ?? null;

        return is_numeric($height) && in_array((int) $height, [10, 100, 150], true)
            ? (int) $height
            : 100;
    }

    /** @param array<string, mixed> $reading */
    private function isDwdFallback(array $reading): bool
    {
        return ($reading['provider_identifier'] ?? null) === 'dwd_mosmix_bright_sky';
    }

    /**
     * @param  array<string, mixed>  $reading
     * @return array{below_500ft_pct: float, valid_at: string, source: array<string, mixed>}|null
     */
    private function dwdLowCloudBand(array $reading): ?array
    {
        if (! $this->isDwdFallback($reading)
            || ($reading['complete'] ?? false) !== true
            || ($reading['stale'] ?? true) === true
            || ($reading['cloud_cover_low_complete'] ?? false) !== true
            || ($reading['cloud_cover_below_500ft_complete'] ?? false) !== true) {
            return null;
        }

        $low = $this->roundedOrNull($reading['cloud_cover_low_pct'] ?? null, 0);
        $below500 = $this->roundedOrNull($reading['cloud_cover_below_500ft_pct'] ?? null, 0);
        $lowSamples = $reading['cloud_cover_low_sample_count'] ?? null;
        $lowExpected = $reading['cloud_cover_low_expected_sample_count'] ?? null;
        $belowSamples = $reading['cloud_cover_below_500ft_sample_count'] ?? null;
        $belowExpected = $reading['cloud_cover_below_500ft_expected_sample_count'] ?? null;
        $validAt = $reading['cloud_cover_low_valid_at'] ?? null;
        $weatherValidAt = $reading['valid_at'] ?? null;
        $modelRunAt = $reading['cloud_cover_low_model_run_at'] ?? null;
        $source = $reading['cloud_cover_low_source'] ?? null;
        if ($low === null
            || $below500 === null
            || $low < 0
            || $low > 100
            || $below500 < 0
            || $below500 > 100
            || ! is_int($lowSamples)
            || ! is_int($lowExpected)
            || ! is_int($belowSamples)
            || ! is_int($belowExpected)
            || $lowExpected < 1
            || $lowExpected > WallboardForecastLocationService::NETHERLANDS_PROVINCE_COUNT
            || $lowSamples !== $lowExpected
            || $belowSamples !== $belowExpected
            || $belowExpected !== $lowExpected
            || ! is_string($validAt)
            || ! is_string($weatherValidAt)
            || $validAt !== $weatherValidAt
            || ! is_string($modelRunAt)
            || ! is_array($source)
            || ! is_string($source['name'] ?? null)
            || trim($source['name']) === '') {
            return null;
        }

        try {
            $valid = $this->timestamp($validAt);
            $modelRun = $this->timestamp($modelRunAt);
        } catch (Throwable) {
            return null;
        }
        if (! $modelRun->lessThan($valid)) {
            return null;
        }

        return [
            'below_500ft_pct' => $below500,
            'valid_at' => $validAt,
            'source' => $source,
        ];
    }

    /** @param array<string, mixed> $reading */
    private function structuredAttribution(array $reading): string
    {
        return $this->isDwdFallback($reading) ? 'DWD_MOSMIX' : 'DMI';
    }

    /** @param array<string, mixed> $reading */
    private function nationalScopeNote(array $reading): string
    {
        if ($this->isDwdFallback($reading)) {
            return $this->dwdLowCloudBand($reading) !== null
                ? 'Rekenkundig gemiddelde van actuele DWD MOSMIX-modelwaarden via Bright Sky voor exact alle 12 Nederlandse provinciepunten; de zwaarste weersconditie, hoogste neerslagpiek en hoogste Nl/N05-bewolking blijven conservatief leidend. Alleen 10 m-oppervlaktewind is beschikbaar; bovenwind, middelbare/hoge wolkenlagen en een exacte modelwolkenbasis blijven onbekend.'
                : 'Rekenkundig gemiddelde van actuele DWD MOSMIX-modelwaarden via Bright Sky voor exact alle 12 Nederlandse provinciepunten; de zwaarste weersconditie en hoogste neerslagpiek blijven conservatief leidend. Alleen 10 m-oppervlaktewind is beschikbaar; bovenwind, lage/middelbare/hoge wolkenlagen en modelwolkenbasis blijven onbekend.';
        }

        return 'Rekenkundig gemiddelde van actuele DMI-modelwaarden voor exact alle 12 Nederlandse provincies; windrichting is een circulair gemiddelde, de modelwolkenbasis is het laagste geldige provinciepunt en zonopkomst/-ondergang worden als landelijke tijdsrange getoond.';
    }

    /** @param array<string, mixed> $reading */
    private function addressScopeNote(array $reading): string
    {
        if ($this->isDwdFallback($reading)) {
            return $this->dwdLowCloudBand($reading) !== null
                ? 'Actuele DWD MOSMIX-modelwaarden via Bright Sky voor het server-side opgeloste adres. Lage bewolking onder 2 km en onder 500 ft komt rechtstreeks uit DWD MOSMIX_L; alleen 10 m-oppervlaktewind is beschikbaar, bovenwind blijft onbekend en een exacte modelwolkenbasis wordt niet afgeleid.'
                : 'Actuele DWD MOSMIX-modelwaarden via Bright Sky voor het server-side opgeloste adres. Alleen 10 m-oppervlaktewind is beschikbaar; bovenwind, afzonderlijke wolkenlagen en modelwolkenbasis blijven onbekend.';
        }

        return 'Actuele DMI-modelwaarden voor het server-side opgeloste adres; KNMI-stationsmetingen van de wolkenbasis blijven afzonderlijke puntmetingen.';
    }

    /** @param array<string, mixed> $reading */
    private function disclaimer(array $reading): string
    {
        if ($this->isDwdFallback($reading)) {
            $cloudNote = $this->dwdLowCloudBand($reading) !== null
                ? ' Lage bewolking wordt rechtstreeks beoordeeld voor de banden onder 2 km en onder 500 ft; daaruit wordt geen exacte wolkenbasishoogte afgeleid.'
                : ' Ontbrekende hoogte- en wolkenbasisdata blijven onbekend.';

            return 'Indicatief vliegadvies op basis van DWD MOSMIX.'.$cloudNote.' Modelwind is alleen op 10 m boven maaiveld beschikbaar. Toestellimieten, missieprofiel, lokale weerswaarneming, luchtruimregels en gezaghebbende operationele beoordeling gaan altijd voor.';
        }

        return 'Indicatief vliegadvies. Modelwind wordt expliciet op 10, 100 en 150 m boven maaiveld getoond; windstoten zijn alleen als 10 m-grondwaarde beschikbaar. Toestellimieten, missieprofiel, lokale weerswaarneming, luchtruimregels en gezaghebbende operationele beoordeling gaan altijd voor.';
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

    private function timestamp(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    /** @param array<string, mixed> $weather
     * @param  array<string, mixed>  $kp
     * @param  array<string, mixed>  $cloudForecast
     * @param  array<string, mixed>  $gnss
     */
    private function forecastGeneratedAt(
        array $weather,
        array $kp,
        array $cloudForecast,
        array $gnss,
    ): string {
        $latest = null;
        $timestamps = [
            $weather['refreshed_at'] ?? null,
            $kp['refreshed_at'] ?? null,
            $cloudForecast['refreshed_at'] ?? null,
        ];
        if (($gnss['complete'] ?? false) === true && ($gnss['stale'] ?? true) === false) {
            $timestamps[] = $gnss['measured_at'] ?? null;
        }

        foreach ($timestamps as $value) {
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

    private function positiveConfig(string $key, int $fallback): int
    {
        $value = config("dis.wallboards.uav_forecast.{$key}", $fallback);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}
