<?php

namespace App\Services;

use App\Contracts\KnmiPrecipitationOutlookProvider;
use App\Repositories\KnmiPrecipitationSnapshotRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class KnmiPrecipitationOutlookService implements KnmiPrecipitationOutlookProvider
{
    private const CACHE_NAMESPACE = 'wallboard:uav-forecast:knmi-precipitation-local:v1';

    private const RADAR_SAMPLE_COUNT = 25;

    private const THIRD_HOUR_SAMPLE_COUNT = 13;

    public function __construct(
        private readonly KnmiPrecipitationSnapshotRepository $snapshots,
        private readonly KnmiPrecipitationHdf5Reader $reader,
        private readonly KnmiPrecipitationConfiguration $configuration,
    ) {}

    /**
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    public function forResolution(array $resolution): array
    {
        $validated = $this->validatedResolution($resolution);
        if ($validated === null) {
            return $this->unavailable(
                'De gekozen locatie kon niet volledig en veilig worden bepaald.',
                0,
                $this->expectedLocationCount($resolution),
            );
        }
        try {
            $snapshot = $this->snapshots->activeSnapshot();
            if ($snapshot === null) {
                return $this->unavailable(
                    'Er is nog geen complete lokale KNMI-neerslagsnapshot actief.',
                    0,
                    $validated['expected_location_count'],
                );
            }
            $reference = $this->timestamp($snapshot['reference_time']);
            $activatedAt = $this->timestamp($snapshot['activated_at']);
            $now = CarbonImmutable::now()->utc();
            if ($reference->greaterThan($now->addMinutes(10))) {
                return $this->unavailable(
                    'De lokale KNMI-neerslagsnapshot heeft een ongeldige toekomstige referentietijd.',
                    0,
                    $validated['expected_location_count'],
                );
            }
            $cacheKey = self::CACHE_NAMESPACE.':resolution:'.$snapshot['snapshot_id'].':'
                .sha1(json_encode($validated['locations'], JSON_THROW_ON_ERROR));

            $result = Cache::remember(
                $cacheKey,
                $this->configuration->pointCacheSeconds(),
                fn (): array => $this->aggregate(
                    $snapshot,
                    $reference,
                    $activatedAt,
                    $validated['locations'],
                    $validated['expected_location_count'],
                ),
            );

            return $this->withCurrentFreshness($result, $reference);
        } catch (Throwable) {
            return $this->unavailable(
                'De lokale KNMI-neerslagsnapshot is onvolledig of ongeldig.',
                0,
                $validated['expected_location_count'],
            );
        }
    }

    /** @param array<string, mixed> $resolution */
    public function prewarmResolution(array $resolution): void
    {
        $result = $this->forResolution($resolution);
        if (($result['complete'] ?? false) !== true) {
            throw new \RuntimeException('The local KNMI precipitation snapshot could not be prewarmed.');
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array{latitude: float, longitude: float}>  $locations
     * @return array<string, mixed>
     */
    private function aggregate(
        array $snapshot,
        CarbonImmutable $reference,
        CarbonImmutable $activatedAt,
        array $locations,
        int $expectedLocationCount,
    ): array {
        $readings = [];
        foreach ($locations as $location) {
            $pointKey = self::CACHE_NAMESPACE.':point:'.$snapshot['snapshot_id'].':'
                .sha1(sprintf('%.5F,%.5F', $location['latitude'], $location['longitude']));
            $readings[] = Cache::remember(
                $pointKey,
                $this->configuration->pointCacheSeconds(),
                fn (): array => $this->reader->readPoint(
                    $snapshot['paths']['radar'],
                    $snapshot['paths']['probability'],
                    $reference,
                    $location['latitude'],
                    $location['longitude'],
                ),
            );
        }
        if (count($readings) !== $expectedLocationCount || $readings === []) {
            return $this->unavailable(
                'Het vereiste aantal lokale KNMI-neerslaglocaties is niet compleet.',
                count($readings),
                $expectedLocationCount,
            );
        }

        $firstPrecipitation = null;
        foreach ($readings as $reading) {
            if (($reading['reference_time'] ?? null) !== $reference->toIso8601String()
                || ($reading['radar_sample_count'] ?? null) !== self::RADAR_SAMPLE_COUNT
                || ($reading['third_hour_sample_count'] ?? null) !== self::THIRD_HOUR_SAMPLE_COUNT
                || ! is_numeric($reading['radar_peak_mm_h'] ?? null)
                || ! is_numeric($reading['third_hour_probability_pct'] ?? null)) {
                return $this->unavailable(
                    'De lokale KNMI-neerslagreeks is niet voor alle locaties compleet.',
                    count($readings),
                    $expectedLocationCount,
                );
            }
            $candidate = $reading['radar_first_precipitation_at'] ?? null;
            if (is_string($candidate)) {
                $time = $this->timestamp($candidate);
                if ($firstPrecipitation === null || $time->lessThan($firstPrecipitation)) {
                    $firstPrecipitation = $time;
                }
            }
        }

        return [
            'complete' => true,
            'stale' => false,
            'radar_peak_mm_h' => max(array_map(
                static fn (array $reading): float => (float) $reading['radar_peak_mm_h'],
                $readings,
            )),
            'radar_first_precipitation_at' => $firstPrecipitation?->toIso8601String(),
            'radar_until' => $reference->addMinutes(120)->toIso8601String(),
            'third_hour_probability_pct' => max(array_map(
                static fn (array $reading): float => (float) $reading['third_hour_probability_pct'],
                $readings,
            )),
            'third_hour_from' => $reference->addMinutes(120)->toIso8601String(),
            'forecast_until' => $reference->addMinutes(180)->toIso8601String(),
            'reference_time' => $reference->toIso8601String(),
            'radar_sample_count' => self::RADAR_SAMPLE_COUNT * count($readings),
            'third_hour_sample_count' => self::THIRD_HOUR_SAMPLE_COUNT * count($readings),
            'sample_count' => count($readings),
            'expected_sample_count' => $expectedLocationCount,
            'source' => $this->source(count($readings)),
            'availability_note' => null,
            'measured_at' => $reference->toIso8601String(),
            'refreshed_at' => $activatedAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @return array{locations: list<array{latitude: float, longitude: float}>, expected_location_count: int}|null
     */
    private function validatedResolution(array $resolution): ?array
    {
        $expected = $this->expectedLocationCount($resolution);
        $rawLocations = $resolution['locations'] ?? null;
        if (($resolution['complete'] ?? false) !== true
            || $expected < 1
            || $expected > 12
            || ! is_array($rawLocations)
            || ! array_is_list($rawLocations)
            || count($rawLocations) !== $expected) {
            return null;
        }

        $locations = [];
        $unique = [];
        foreach ($rawLocations as $location) {
            if (! is_array($location)
                || ! is_numeric($location['latitude'] ?? null)
                || ! is_numeric($location['longitude'] ?? null)) {
                return null;
            }
            $latitude = (float) $location['latitude'];
            $longitude = (float) $location['longitude'];
            if (! is_finite($latitude)
                || ! is_finite($longitude)
                || $latitude < -90.0
                || $latitude > 90.0
                || $longitude < -180.0
                || $longitude > 180.0) {
                return null;
            }
            $key = sprintf('%.5F,%.5F', $latitude, $longitude);
            if (isset($unique[$key])) {
                return null;
            }
            $unique[$key] = true;
            $locations[] = ['latitude' => $latitude, 'longitude' => $longitude];
        }

        return ['locations' => $locations, 'expected_location_count' => $expected];
    }

    /** @param array<string, mixed> $resolution */
    private function expectedLocationCount(array $resolution): int
    {
        $value = $resolution['expected_locations'] ?? 0;

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /** @return array<string, mixed> */
    private function unavailable(string $note, int $sampleCount, int $expectedSampleCount): array
    {
        return [
            'complete' => false,
            'stale' => false,
            'radar_peak_mm_h' => null,
            'radar_first_precipitation_at' => null,
            'radar_until' => null,
            'third_hour_probability_pct' => null,
            'third_hour_from' => null,
            'forecast_until' => null,
            'reference_time' => null,
            'radar_sample_count' => 0,
            'third_hour_sample_count' => 0,
            'sample_count' => $sampleCount,
            'expected_sample_count' => max(0, $expectedSampleCount),
            'source' => $this->source(max(1, $expectedSampleCount)),
            'availability_note' => $note,
            'measured_at' => null,
            'refreshed_at' => null,
        ];
    }

    /** @return array{name: string, url: string} */
    private function source(int $sampleCount): array
    {
        return [
            'name' => $sampleCount > 1
                ? "KNMI lokale radar + ensemblekans ({$sampleCount} locaties)"
                : 'KNMI lokale radar + ensemblekans',
            'url' => 'https://dataplatform.knmi.nl/',
        ];
    }

    private function timestamp(string $value): CarbonImmutable
    {
        if (strlen($value) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            throw new \UnexpectedValueException('KNMI precipitation timestamp is invalid.');
        }

        return CarbonImmutable::parse($value)->utc();
    }

    /** @param array<string, mixed> $reading
     * @return array<string, mixed>
     */
    private function withCurrentFreshness(array $reading, CarbonImmutable $reference): array
    {
        if (($reading['complete'] ?? false) !== true) {
            return $reading;
        }
        $stale = $reference->lessThan(
            CarbonImmutable::now()->utc()->subSeconds($this->configuration->maximumReferenceAgeSeconds()),
        );
        $reading['stale'] = $stale;
        $reading['availability_note'] = $stale
            ? 'De actieve lokale KNMI-snapshot is verouderd; de kaart telt daarom fail-closed als onbekend.'
            : null;

        return $reading;
    }
}
