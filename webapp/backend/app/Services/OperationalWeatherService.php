<?php

namespace App\Services;

use App\Contracts\KnmiCloudForecastProvider;
use App\Contracts\KnmiPrecipitationOutlookProvider;
use Carbon\CarbonImmutable;
use Throwable;

final class OperationalWeatherService
{
    public function __construct(
        private readonly WallboardForecastLocationService $locations,
        private readonly KnmiCloudForecastProvider $cloudForecasts,
        private readonly KnmiPrecipitationOutlookProvider $precipitationOutlooks,
        private readonly KnmiPrecipitationConfiguration $precipitationConfiguration,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function forecastForOptions(array $options): array
    {
        $resolution = $this->locations->resolve($options);
        $expectedSampleCount = (int) ($resolution['expected_locations'] ?? 0);
        $cloud = $this->cloud(
            $this->cloudForecasts->forResolution($resolution),
            $expectedSampleCount,
        );
        $precipitation = $this->precipitation(
            $this->precipitationOutlooks->forResolution($resolution),
            $expectedSampleCount,
        );
        $cloudCurrent = $cloud['complete'] && ! $cloud['stale'];
        $precipitationCurrent = $precipitation['complete'] && ! $precipitation['stale'];
        $precipitationFullyCurrent = $precipitationCurrent
            && ($precipitation['probability_complete'] ?? false) === true;
        $dataStatus = $cloudCurrent && $precipitationFullyCurrent
            ? 'current'
            : ($cloudCurrent || $precipitationCurrent ? 'partial' : 'unavailable');
        $centre = $this->centre(($resolution['complete'] ?? false) === true
            ? (array) ($resolution['locations'] ?? [])
            : []);

        return [
            'location' => [
                'mode' => (string) ($resolution['mode'] ?? WallboardForecastLocationService::MODE_NETHERLANDS),
                'label' => (string) ($resolution['label'] ?? WallboardForecastLocationService::NETHERLANDS_LABEL),
                'latitude' => $centre['latitude'],
                'longitude' => $centre['longitude'],
            ],
            'aggregation' => [
                'type' => ($resolution['mode'] ?? null) === WallboardForecastLocationService::MODE_NETHERLANDS
                    ? 'province_average'
                    : 'single_location',
                'sample_count' => min(
                    $cloud['complete'] ? $cloud['sample_count'] : 0,
                    $precipitation['complete'] ? $precipitation['sample_count'] : 0,
                ),
                'expected_sample_count' => max(0, $expectedSampleCount),
                'complete' => $cloud['complete']
                    && $precipitation['complete']
                    && ($precipitation['probability_complete'] ?? false) === true,
                'fresh' => $dataStatus === 'current',
            ],
            'generated_at' => $this->generatedAt($cloud, $precipitation),
            'data_status' => $dataStatus,
            'cloud' => $cloud,
            'precipitation' => $precipitation,
            'scope_note' => ($resolution['mode'] ?? null) === WallboardForecastLocationService::MODE_NETHERLANDS
                ? (($precipitation['probability_complete'] ?? false) === true
                    ? 'Landelijk beeld op basis van exact twaalf beheerde provinciepunten; bewolking is gemiddeld, de modelwolkenbasis is het laagste geldige punt en neerslagpiek en -kans zijn de hoogste provinciewaarden.'
                    : 'Landelijk beeld op basis van exact twaalf beheerde provinciepunten; bewolking is gemiddeld, de modelwolkenbasis is het laagste geldige punt en de radarpiek is de hoogste provinciewaarde. De kans voor uur 3 is onbekend.')
                : 'Lokale KNMI-model- en neerslagwaarden voor het server-side opgeloste adres.',
            'disclaimer' => 'Uitsluitend indicatieve lokale KNMI-model- en neerslagdata. Controleer altijd actuele lokale waarnemingen, waarschuwingen, toestellimieten en operationele omstandigheden voordat u vliegt.',
        ];
    }

    /**
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>
     */
    private function cloud(array $reading, int $expectedSampleCount): array
    {
        $sampleCount = $this->count($reading['sample_count'] ?? null);
        $reportedExpected = $this->count($reading['expected_sample_count'] ?? null);
        $expected = max(0, $expectedSampleCount);
        $covers = [
            'cloud_cover_pct' => $this->number($reading['cloud_cover_pct'] ?? null, 0, 100),
            'cloud_cover_low_pct' => $this->number($reading['cloud_cover_low_pct'] ?? null, 0, 100),
            'cloud_cover_mid_pct' => $this->number($reading['cloud_cover_mid_pct'] ?? null, 0, 100),
            'cloud_cover_high_pct' => $this->number($reading['cloud_cover_high_pct'] ?? null, 0, 100),
        ];
        $modelRunAt = $this->timestamp($reading['model_run_at'] ?? null);
        $validAt = $this->timestamp($reading['valid_at'] ?? null);
        $measuredAt = $this->timestamp($reading['measured_at'] ?? null);
        $refreshedAt = $this->timestamp($reading['refreshed_at'] ?? null);
        $timestampsComplete = $modelRunAt !== null
            && $validAt !== null
            && $measuredAt !== null
            && $refreshedAt !== null
            && $modelRunAt->lessThanOrEqualTo($validAt)
            && $measuredAt->equalTo($validAt);
        $timestampsFresh = $timestampsComplete && $this->cloudTimestampsAreFresh(
            $modelRunAt,
            $validAt,
            $refreshedAt,
        );
        $stale = (bool) ($reading['stale'] ?? false) || ($timestampsComplete && ! $timestampsFresh);
        $complete = ($reading['complete'] ?? false) === true
            && $expected > 0
            && $reportedExpected === $expected
            && $sampleCount === $expected
            && ! in_array(null, $covers, true)
            && $timestampsFresh
            && ! $stale;

        return [
            'complete' => $complete,
            'stale' => $stale,
            ...$covers,
            'cloud_base_m' => $this->number($reading['cloud_base_m'] ?? null, 0, 60000),
            'model_run_at' => $this->timestampString($reading['model_run_at'] ?? null, $modelRunAt),
            'valid_at' => $this->timestampString($reading['valid_at'] ?? null, $validAt),
            'measured_at' => $this->timestampString($reading['measured_at'] ?? null, $measuredAt),
            'refreshed_at' => $this->timestampString($reading['refreshed_at'] ?? null, $refreshedAt),
            'sample_count' => $sampleCount,
            'expected_sample_count' => $expected,
            'source' => $this->source($reading['source'] ?? null, 'KNMI HARMONIE P1'),
            'availability_note' => $this->availabilityNote(
                $reading,
                $complete,
                'De lokale KNMI HARMONIE-bewolkingsdata is niet compleet beschikbaar.',
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $reading
     * @return array<string, mixed>
     */
    private function precipitation(array $reading, int $expectedSampleCount): array
    {
        $sampleCount = $this->count($reading['sample_count'] ?? null);
        $reportedExpected = $this->count($reading['expected_sample_count'] ?? null);
        $expected = max(0, $expectedSampleCount);
        $peak = $this->number($reading['radar_peak_mm_h'] ?? null, 0, 1000);
        $probability = $this->number($reading['third_hour_probability_pct'] ?? null, 0, 100);
        $referenceTime = $this->timestamp($reading['reference_time'] ?? null);
        $radarUntil = $this->timestamp($reading['radar_until'] ?? null);
        $thirdHourFrom = $this->timestamp($reading['third_hour_from'] ?? null);
        $forecastUntil = $this->timestamp($reading['forecast_until'] ?? null);
        $measuredAt = $this->timestamp($reading['measured_at'] ?? null);
        $refreshedAt = $this->timestamp($reading['refreshed_at'] ?? null);
        $firstPrecipitationValue = $reading['radar_first_precipitation_at'] ?? null;
        $firstPrecipitationAt = $firstPrecipitationValue === null
            ? null
            : $this->timestamp($firstPrecipitationValue);
        $firstPrecipitationValid = $firstPrecipitationValue === null
            || ($firstPrecipitationAt !== null
                && $referenceTime !== null
                && $radarUntil !== null
                && $firstPrecipitationAt->betweenIncluded($referenceTime, $radarUntil));
        $radarTimestampsComplete = $referenceTime !== null
            && $radarUntil !== null
            && $measuredAt !== null
            && $refreshedAt !== null
            && $measuredAt->equalTo($referenceTime)
            && $radarUntil->equalTo($referenceTime->addMinutes(120))
            && $firstPrecipitationValid;
        $timestampsFresh = $radarTimestampsComplete && $this->precipitationTimestampsAreFresh(
            $referenceTime,
            $refreshedAt,
        );
        $stale = (bool) ($reading['stale'] ?? false) || ($radarTimestampsComplete && ! $timestampsFresh);
        $complete = ($reading['complete'] ?? false) === true
            && $expected > 0
            && $reportedExpected === $expected
            && $sampleCount === $expected
            && $peak !== null
            && $this->count($reading['radar_sample_count'] ?? null) === KnmiPrecipitationOutlookService::RADAR_SAMPLE_COUNT * $expected
            && $timestampsFresh
            && ! $stale;
        $probabilityComplete = $complete
            && $probability !== null
            && $thirdHourFrom !== null
            && $forecastUntil !== null
            && $thirdHourFrom->equalTo($radarUntil)
            && $forecastUntil->equalTo($referenceTime->addMinutes(180))
            && $this->count($reading['third_hour_sample_count'] ?? null) === KnmiPrecipitationOutlookService::THIRD_HOUR_SAMPLE_COUNT * $expected;
        $availabilityNote = $this->availabilityNote(
            $reading,
            $complete,
            'De lokale KNMI-neerslagdata is niet compleet beschikbaar.',
        );
        if ($complete && ! $probabilityComplete && $availabilityNote === null) {
            $availabilityNote = 'De lokale KNMI-radar is beschikbaar; de afzonderlijke ensemblekans voor uur 3 ontbreekt.';
        }

        return [
            'complete' => $complete,
            'probability_complete' => $probabilityComplete,
            'stale' => $stale,
            'radar_peak_mm_h' => $peak,
            'radar_first_precipitation_at' => $this->timestampString($firstPrecipitationValue, $firstPrecipitationAt),
            'radar_until' => $this->timestampString($reading['radar_until'] ?? null, $radarUntil),
            'third_hour_probability_pct' => $probabilityComplete ? $probability : null,
            'third_hour_from' => $probabilityComplete
                ? $this->timestampString($reading['third_hour_from'] ?? null, $thirdHourFrom)
                : null,
            'forecast_until' => $probabilityComplete
                ? $this->timestampString($reading['forecast_until'] ?? null, $forecastUntil)
                : null,
            'reference_time' => $this->timestampString($reading['reference_time'] ?? null, $referenceTime),
            'measured_at' => $this->timestampString($reading['measured_at'] ?? null, $measuredAt),
            'refreshed_at' => $this->timestampString($reading['refreshed_at'] ?? null, $refreshedAt),
            'sample_count' => $sampleCount,
            'expected_sample_count' => $expected,
            'source' => $this->source(
                $reading['source'] ?? null,
                $probabilityComplete ? 'KNMI lokale radar + ensemblekans' : 'KNMI lokale radar',
            ),
            'availability_note' => $availabilityNote,
        ];
    }

    /**
     * @param  array<string, mixed>  $cloud
     * @param  array<string, mixed>  $precipitation
     */
    private function generatedAt(array $cloud, array $precipitation): string
    {
        $latest = null;
        foreach ([$cloud, $precipitation] as $reading) {
            if (($reading['complete'] ?? false) !== true) {
                continue;
            }
            $value = $reading['refreshed_at'] ?? null;
            if (! is_string($value)) {
                continue;
            }
            try {
                $candidate = CarbonImmutable::parse($value)->utc();
            } catch (Throwable) {
                continue;
            }
            if ($latest === null || $candidate->greaterThan($latest)) {
                $latest = $candidate;
            }
        }

        return ($latest ?? CarbonImmutable::now('UTC'))->toIso8601String();
    }

    private function cloudTimestampsAreFresh(
        CarbonImmutable $modelRunAt,
        CarbonImmutable $validAt,
        CarbonImmutable $refreshedAt,
    ): bool {
        $now = CarbonImmutable::now('UTC');
        $maximumModelAge = $this->positiveConfig('maximum_model_age_seconds', 21600, 3600, 86400);
        $maximumValidOffset = $this->positiveConfig('maximum_valid_offset_seconds', 3600, 1800, 7200);

        return ! $modelRunAt->greaterThan($now->addMinutes(10))
            && ! $modelRunAt->lessThan($now->subSeconds($maximumModelAge))
            && abs($validAt->getTimestamp() - $now->getTimestamp()) <= $maximumValidOffset
            && ! $refreshedAt->lessThan($modelRunAt)
            && ! $refreshedAt->greaterThan($now->addMinutes(10));
    }

    private function precipitationTimestampsAreFresh(
        CarbonImmutable $referenceTime,
        CarbonImmutable $refreshedAt,
    ): bool {
        $now = CarbonImmutable::now('UTC');

        return ! $referenceTime->greaterThan($now->addMinutes(10))
            && ! $referenceTime->lessThan(
                $now->subSeconds($this->precipitationConfiguration->maximumReferenceAgeSeconds()),
            )
            && ! $refreshedAt->lessThan($referenceTime)
            && ! $refreshedAt->greaterThan($now->addMinutes(10));
    }

    /**
     * @param  list<array<string, mixed>>  $locations
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

    /** @return array{name: string, url: string|null} */
    private function source(mixed $source, string $fallbackName): array
    {
        return [
            'name' => is_array($source) && is_string($source['name'] ?? null)
                ? $source['name']
                : $fallbackName,
            'url' => is_array($source) && is_string($source['url'] ?? null)
                ? $source['url']
                : null,
        ];
    }

    /** @param array<string, mixed> $reading */
    private function availabilityNote(array $reading, bool $complete, string $fallback): ?string
    {
        $note = $this->string($reading['availability_note'] ?? null);

        return $note ?? ($complete ? null : $fallback);
    }

    private function number(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }
        $number = (float) $value;

        return $number >= $minimum && $number <= $maximum ? $number : null;
    }

    private function count(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        $string = $this->string($value);
        if ($string === null
            || strlen($string) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/D', $string) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($string)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function timestampString(mixed $value, ?CarbonImmutable $timestamp): ?string
    {
        return $timestamp === null ? null : $this->string($value);
    }

    private function positiveConfig(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = (int) config('dis.knmi_forecast.'.$key, $default);

        return max($minimum, min($maximum, $value));
    }
}
