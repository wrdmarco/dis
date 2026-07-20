<?php

namespace App\Services;

use App\Contracts\KnmiCloudForecastProvider;
use App\Repositories\KnmiForecastSnapshotRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

final class KnmiHarmonieCloudService implements KnmiCloudForecastProvider
{
    private const SOURCE_URL = 'https://dataplatform.knmi.nl/dataset/harmonie-arome-cy43-p1-1-0';

    private const RESOLUTION_CACHE_VERSION = 1;

    private const NEGATIVE_RESOLUTION_CACHE_SECONDS = 60;

    private const MAX_PROCESS_OUTPUT_BYTES = 8192;

    /** @var array<int, array{level_type: int, level: int, time_range: int}> */
    private const PARAMETERS = [
        71 => ['level_type' => 105, 'level' => 0, 'time_range' => 0],
        73 => ['level_type' => 105, 'level' => 0, 'time_range' => 0],
        74 => ['level_type' => 105, 'level' => 0, 'time_range' => 0],
        75 => ['level_type' => 105, 'level' => 0, 'time_range' => 0],
        186 => ['level_type' => 200, 'level' => 0, 'time_range' => 0],
    ];

    public function __construct(private readonly KnmiForecastSnapshotRepository $snapshots) {}

    /**
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    public function forResolution(array $resolution): array
    {
        if (! ($resolution['complete'] ?? false)) {
            return $this->unavailable('De gekozen locatie kon niet volledig server-side worden bepaald.');
        }

        try {
            $active = $this->snapshots->closestMember(CarbonImmutable::now('UTC'));
        } catch (Throwable) {
            return $this->unavailable('De centrale KNMI-modelset is niet veilig beschikbaar.');
        }
        if ($active === null) {
            return $this->unavailable('Er is nog geen gevalideerde KNMI HARMONIE-modelset geïnstalleerd.');
        }

        $snapshot = $active['snapshot'];
        $member = $active['member'];
        $path = $active['path'];
        try {
            $modelRunAt = CarbonImmutable::instance($snapshot->model_run_at)->utc();
            $validAt = CarbonImmutable::parse($member['valid_at'])->utc();
        } catch (Throwable) {
            return $this->unavailable('De tijdstempels van de actieve KNMI-modelset zijn ongeldig.');
        }
        $now = CarbonImmutable::now('UTC');
        $maximumModelAge = $this->positiveConfig('maximum_model_age_seconds', 21600, 3600, 86400);
        $maximumValidOffset = $this->positiveConfig('maximum_valid_offset_seconds', 3600, 1800, 7200);
        if ($modelRunAt->greaterThan($now->addMinutes(10))
            || $modelRunAt->lessThan($now->subSeconds($maximumModelAge))
            || abs($validAt->getTimestamp() - $now->getTimestamp()) > $maximumValidOffset) {
            return $this->unavailable('De laatst gevalideerde KNMI-modelrun is te oud voor actueel vliegadvies.', true);
        }
        if (! $this->memberIntegrityMatches($snapshot->id, $member, $path)) {
            return $this->unavailable('Het actieve KNMI-modelbestand is niet meer intact.');
        }

        $locations = $resolution['locations'] ?? null;
        $expected = $resolution['expected_locations'] ?? null;
        if (! is_array($locations)
            || ! array_is_list($locations)
            || ! is_int($expected)
            || $expected < 1
            || $expected > WallboardForecastLocationService::NETHERLANDS_PROVINCE_COUNT
            || count($locations) !== $expected) {
            return $this->unavailable('Het vereiste aantal KNMI-roosterpunten kon niet worden bepaald.');
        }

        $validatedLocations = [];
        foreach ($locations as $location) {
            if (! is_array($location)
                || ! is_numeric($location['latitude'] ?? null)
                || ! is_numeric($location['longitude'] ?? null)) {
                return $this->unavailable('Een KNMI-roosterpunt bevat ongeldige coördinaten.');
            }
            $latitude = (float) $location['latitude'];
            $longitude = (float) $location['longitude'];
            if (! $this->validCoordinates($latitude, $longitude)) {
                return $this->unavailable('Een KNMI-roosterpunt valt buiten het Nederlandse modeldomein.');
            }
            $validatedLocations[] = ['latitude' => $latitude, 'longitude' => $longitude];
        }

        $resolutionCacheKey = $this->resolutionCacheKey(
            (string) $snapshot->id,
            $member['sha256'],
            $expected,
            $validatedLocations,
        );
        try {
            $cached = $this->cachedResolution(Cache::get($resolutionCacheKey));
            if ($cached !== null) {
                return $cached;
            }
            $queryTimeout = $this->positiveConfig('query_timeout_seconds', 10, 1, 30);
            $lock = Cache::lock($resolutionCacheKey.':lock', $queryTimeout + 10);
            if (! $lock->get()) {
                return $this->unavailable('De KNMI-roosterpunten worden al uitgelezen; deze aanvraag blijft uit veiligheid onbekend.');
            }
        } catch (Throwable) {
            return $this->unavailable('De KNMI-uitleesvergrendeling is niet veilig beschikbaar.');
        }

        try {
            $cached = $this->cachedResolution(Cache::get($resolutionCacheKey));
            if ($cached !== null) {
                return $cached;
            }
            $reading = $this->loadResolution(
                snapshotId: (string) $snapshot->id,
                memberSha256: $member['sha256'],
                path: $path,
                locations: $validatedLocations,
                expected: $expected,
                modelRunAt: $modelRunAt,
                validAt: $validAt,
                refreshedAt: CarbonImmutable::instance($snapshot->activated_at)->utc(),
                queryTimeout: $queryTimeout,
            );
            Cache::put(
                $resolutionCacheKey,
                $this->resolutionCacheEntry($reading),
                ($reading['complete'] ?? false) === true
                    ? $this->positiveConfig('point_cache_seconds', 21600, 300, 86400)
                    : self::NEGATIVE_RESOLUTION_CACHE_SECONDS,
            );

            return $reading;
        } catch (Throwable) {
            $reading = $this->unavailable('De actieve KNMI-modelset kon niet betrouwbaar worden uitgelezen.');
            try {
                Cache::put(
                    $resolutionCacheKey,
                    $this->resolutionCacheEntry($reading),
                    self::NEGATIVE_RESOLUTION_CACHE_SECONDS,
                );
            } catch (Throwable) {
                // The response remains fail-closed when the negative cache is unavailable.
            }

            return $reading;
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // The bounded lock expires automatically even if release fails.
            }
        }
    }

    /**
     * @param  array{filename: string, lead_hours: int, valid_at: string, size_bytes: int, sha256: string}  $member
     */
    private function memberIntegrityMatches(string $snapshotId, array $member, string $path): bool
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $size = @filesize($path);
        if (! is_array($stat)
            || ! is_int($size)
            || $size !== $member['size_bytes']
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || is_link($path)
            || ! is_file($path)) {
            return false;
        }
        $statIdentity = implode('|', [
            (string) ($stat['dev'] ?? ''),
            (string) ($stat['ino'] ?? ''),
            (string) $size,
            (string) ($stat['mtime'] ?? ''),
            (string) ($stat['ctime'] ?? ''),
        ]);
        $key = 'wallboard:uav-forecast:knmi-member-integrity:v2:'.hash(
            'sha256',
            $snapshotId.'|'.$member['sha256'].'|'.$statIdentity,
        );
        try {
            if (Cache::get($key) === true) {
                return true;
            }
        } catch (Throwable) {
            // Continue with the authoritative file hash when cache is unavailable.
        }

        $sha256 = @hash_file('sha256', $path);
        if (! is_string($sha256) || ! hash_equals($member['sha256'], $sha256)) {
            return false;
        }

        try {
            Cache::put($key, true, $this->positiveConfig('integrity_cache_seconds', 900, 60, 3600));
        } catch (Throwable) {
            // A verified member remains usable; resolution locking still fails closed separately.
        }

        return true;
    }

    /**
     * @param  list<array{latitude: float, longitude: float}>  $locations
     * @return array<string, mixed>
     */
    private function loadResolution(
        string $snapshotId,
        string $memberSha256,
        string $path,
        array $locations,
        int $expected,
        CarbonImmutable $modelRunAt,
        CarbonImmutable $validAt,
        CarbonImmutable $refreshedAt,
        int $queryTimeout,
    ): array {
        $points = $this->points(
            snapshotId: $snapshotId,
            memberSha256: $memberSha256,
            path: $path,
            locations: $locations,
            modelRunAt: $modelRunAt,
            validAt: $validAt,
            queryTimeout: $queryTimeout,
        );
        if ($points === null || count($points) !== $expected) {
            return $this->unavailable('De actieve KNMI-modelset kon niet betrouwbaar worden uitgelezen.');
        }

        $cloudBases = array_values(array_filter(
            array_column($points, 'cloud_base_m'),
            static fn (mixed $value): bool => is_float($value),
        ));
        $provinceAggregate = count($points) === WallboardForecastLocationService::NETHERLANDS_PROVINCE_COUNT;

        return [
            'cloud_cover_pct' => $this->average($points, 'cloud_cover_pct'),
            'cloud_cover_low_pct' => $this->average($points, 'cloud_cover_low_pct'),
            'cloud_cover_mid_pct' => $this->average($points, 'cloud_cover_mid_pct'),
            'cloud_cover_high_pct' => $this->average($points, 'cloud_cover_high_pct'),
            'cloud_base_m' => $cloudBases === [] ? null : min($cloudBases),
            'cloud_base_sample_count' => count($cloudBases),
            'cloud_base_aggregation' => $provinceAggregate ? 'minimum_of_province_samples' : 'single_grid_point',
            'sample_count' => count($points),
            'expected_sample_count' => $expected,
            'complete' => true,
            'stale' => false,
            'model_run_at' => $modelRunAt->toIso8601String(),
            'valid_at' => $validAt->toIso8601String(),
            // `measured_at` is retained for the existing metric envelope. Its
            // value is the model validity time, not an observation timestamp.
            'measured_at' => $validAt->toIso8601String(),
            'refreshed_at' => $refreshedAt->toIso8601String(),
            'source' => [
                'name' => $provinceAggregate ? 'KNMI HARMONIE P1 (12 provincies)' : 'KNMI HARMONIE P1',
                'url' => self::SOURCE_URL,
            ],
        ];
    }

    /**
     * @param  list<array{latitude: float, longitude: float}>  $locations
     * @return list<array{cloud_cover_pct: float, cloud_cover_low_pct: float, cloud_cover_mid_pct: float, cloud_cover_high_pct: float, cloud_base_m: float|null}>|null
     */
    private function points(
        string $snapshotId,
        string $memberSha256,
        string $path,
        array $locations,
        CarbonImmutable $modelRunAt,
        CarbonImmutable $validAt,
        int $queryTimeout,
    ): ?array {
        $points = [];
        $missing = [];
        foreach ($locations as $index => $location) {
            $cacheKey = $this->pointCacheKey(
                $snapshotId,
                $memberSha256,
                $location['latitude'],
                $location['longitude'],
            );
            $cached = $this->normalizedPoint(Cache::get($cacheKey));
            if ($cached === null) {
                $missing[$index] = $location;
            } else {
                $points[$index] = $cached;
            }
        }

        if ($missing !== []) {
            if (count($missing) === 1) {
                $index = array_key_first($missing);
                $location = $missing[$index];
                $point = $this->queryPoint(
                    $path,
                    $location['latitude'],
                    $location['longitude'],
                    $modelRunAt,
                    $validAt,
                    $queryTimeout,
                );
                $loaded = $point === null ? null : [$index => $point];
            } else {
                $loaded = $this->queryPointsConcurrently(
                    $path,
                    $missing,
                    $modelRunAt,
                    $validAt,
                    $queryTimeout,
                );
            }
            if ($loaded === null || count($loaded) !== count($missing)) {
                return null;
            }
            foreach ($loaded as $index => $point) {
                $location = $locations[$index];
                Cache::put(
                    $this->pointCacheKey(
                        $snapshotId,
                        $memberSha256,
                        $location['latitude'],
                        $location['longitude'],
                    ),
                    $point,
                    $this->positiveConfig('point_cache_seconds', 21600, 300, 86400),
                );
                $points[$index] = $point;
            }
        }

        ksort($points, SORT_NUMERIC);

        return count($points) === count($locations) ? array_values($points) : null;
    }

    /**
     * @param  array<int, array{latitude: float, longitude: float}>  $locations
     * @return array<int, array{cloud_cover_pct: float, cloud_cover_low_pct: float, cloud_cover_mid_pct: float, cloud_cover_high_pct: float, cloud_base_m: float|null}>|null
     */
    private function queryPointsConcurrently(
        string $path,
        array $locations,
        CarbonImmutable $modelRunAt,
        CarbonImmutable $validAt,
        int $queryTimeout,
    ): ?array {
        try {
            $results = Process::pool(function (Pool $pool) use ($path, $locations, $queryTimeout): void {
                foreach ($locations as $index => $location) {
                    $pool->as((string) $index)
                        ->timeout($queryTimeout)
                        ->command($this->command($path, $location['latitude'], $location['longitude']));
                }
            })->run();
        } catch (Throwable) {
            return null;
        }

        $points = [];
        foreach ($locations as $index => $_location) {
            if (! isset($results[(string) $index])) {
                return null;
            }
            $point = $this->pointFromResult($results[(string) $index], $modelRunAt, $validAt);
            if ($point === null) {
                return null;
            }
            $points[$index] = $point;
        }

        return $points;
    }

    /**
     * @return array{cloud_cover_pct: float, cloud_cover_low_pct: float, cloud_cover_mid_pct: float, cloud_cover_high_pct: float, cloud_base_m: float|null}|null
     */
    private function queryPoint(
        string $path,
        float $latitude,
        float $longitude,
        CarbonImmutable $modelRunAt,
        CarbonImmutable $validAt,
        int $queryTimeout,
    ): ?array {
        try {
            $result = Process::timeout($queryTimeout)
                ->run($this->command($path, $latitude, $longitude));
        } catch (Throwable) {
            return null;
        }

        return $this->pointFromResult($result, $modelRunAt, $validAt);
    }

    /**
     * @return array{cloud_cover_pct: float, cloud_cover_low_pct: float, cloud_cover_mid_pct: float, cloud_cover_high_pct: float, cloud_base_m: float|null}|null
     */
    private function pointFromResult(
        ProcessResult $result,
        CarbonImmutable $modelRunAt,
        CarbonImmutable $validAt,
    ): ?array {
        if (! $result->successful()
            || strlen($result->output()) > self::MAX_PROCESS_OUTPUT_BYTES
            || trim($result->errorOutput()) !== '') {
            return null;
        }

        return $this->parse($result->output(), $modelRunAt, $validAt);
    }

    private function pointCacheKey(
        string $snapshotId,
        string $memberSha256,
        float $latitude,
        float $longitude,
    ): string {
        return 'wallboard:uav-forecast:knmi-point:v2:'.hash(
            'sha256',
            implode('|', [$snapshotId, $memberSha256, sprintf('%.6F', $latitude), sprintf('%.6F', $longitude)]),
        );
    }

    /**
     * @param  list<array{latitude: float, longitude: float}>  $locations
     */
    private function resolutionCacheKey(
        string $snapshotId,
        string $memberSha256,
        int $expected,
        array $locations,
    ): string {
        $coordinates = array_map(
            static fn (array $location): string => sprintf('%.6F,%.6F', $location['latitude'], $location['longitude']),
            $locations,
        );

        return 'wallboard:uav-forecast:knmi-resolution:v1:'.hash(
            'sha256',
            implode('|', [$snapshotId, $memberSha256, (string) $expected, ...$coordinates]),
        );
    }

    /** @return array{version: int, kind: string, reading: array<string, mixed>} */
    private function resolutionCacheEntry(array $reading): array
    {
        return [
            'version' => self::RESOLUTION_CACHE_VERSION,
            'kind' => ($reading['complete'] ?? false) === true ? 'complete' : 'unavailable',
            'reading' => $reading,
        ];
    }

    /** @return array<string, mixed>|null */
    private function cachedResolution(mixed $cached): ?array
    {
        if (! is_array($cached)
            || ($cached['version'] ?? null) !== self::RESOLUTION_CACHE_VERSION
            || ! is_array($cached['reading'] ?? null)) {
            return null;
        }
        $kind = $cached['kind'] ?? null;
        $complete = ($cached['reading']['complete'] ?? false) === true;
        if (($kind === 'complete' && ! $complete) || ($kind === 'unavailable' && $complete)) {
            return null;
        }

        return in_array($kind, ['complete', 'unavailable'], true) ? $cached['reading'] : null;
    }

    /**
     * @return array{cloud_cover_pct: float, cloud_cover_low_pct: float, cloud_cover_mid_pct: float, cloud_cover_high_pct: float, cloud_base_m: float|null}|null
     */
    private function normalizedPoint(mixed $point): ?array
    {
        if (! is_array($point)) {
            return null;
        }
        $result = [];
        foreach (['cloud_cover_pct', 'cloud_cover_low_pct', 'cloud_cover_mid_pct', 'cloud_cover_high_pct'] as $key) {
            $value = $point[$key] ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 100) {
                return null;
            }
            $result[$key] = (float) $value;
        }
        $cloudBase = $point['cloud_base_m'] ?? null;
        if ($cloudBase !== null
            && (! is_numeric($cloudBase) || ! is_finite((float) $cloudBase) || (float) $cloudBase < 0 || (float) $cloudBase > 60000)) {
            return null;
        }
        $result['cloud_base_m'] = $cloudBase === null ? null : (float) $cloudBase;

        return $result;
    }

    /** @return list<string> */
    private function command(string $path, float $latitude, float $longitude): array
    {
        return [
            '/usr/bin/grib_get',
            '-F',
            '%.12g',
            '-B',
            'indicatorOfParameter:i asc',
            '-l',
            sprintf('%.7F,%.7F,1', $latitude, $longitude),
            '-w',
            'indicatorOfParameter:i=71/73/74/75/186',
            '-p',
            'indicatorOfParameter:i,indicatorOfTypeOfLevel:i,level:i,timeRangeIndicator:i,dataDate:i,dataTime:i,validityDate:i,validityTime:i,bitmapPresent:i,numberOfMissing:i,missingValue:d',
            $path,
        ];
    }

    /**
     * @return array{cloud_cover_pct: float, cloud_cover_low_pct: float, cloud_cover_mid_pct: float, cloud_cover_high_pct: float, cloud_base_m: float|null}|null
     */
    private function parse(string $output, CarbonImmutable $modelRunAt, CarbonImmutable $validAt): ?array
    {
        $lines = preg_split('/\R/u', trim($output));
        if (! is_array($lines) || count($lines) !== count(self::PARAMETERS)) {
            return null;
        }

        $values = [];
        foreach ($lines as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (! is_array($columns) || count($columns) !== 12) {
                return null;
            }
            foreach ($columns as $column) {
                if (! is_numeric($column)) {
                    return null;
                }
            }

            [$parameter, $levelType, $level, $timeRange, $dataDate, $dataTime, $validityDate, $validityTime, $bitmapPresent, $numberOfMissing, $missingValue, $value] = array_map(
                static fn (string $column): float => (float) $column,
                $columns,
            );
            $parameterCode = (int) $parameter;
            $definition = self::PARAMETERS[$parameterCode] ?? null;
            if ($definition === null
                || isset($values[$parameterCode])
                || $parameter !== (float) $parameterCode
                || $levelType !== (float) $definition['level_type']
                || $level !== (float) $definition['level']
                || $timeRange !== (float) $definition['time_range']
                || ! in_array($bitmapPresent, [0.0, 1.0], true)
                || $numberOfMissing < 0
                || floor($numberOfMissing) !== $numberOfMissing
                || ! is_finite($missingValue)
                || ! is_finite($value)
                || ! $this->timesMatch((int) $dataDate, (int) $dataTime, $modelRunAt)
                || ! $this->timesMatch((int) $validityDate, (int) $validityTime, $validAt)) {
                return null;
            }

            if ($parameterCode === 186) {
                $values[$parameterCode] = abs($value - $missingValue) < 0.000001
                    ? null
                    : ($value >= 0 && $value <= 60000 ? $value : false);
                if ($values[$parameterCode] === false) {
                    return null;
                }
            } else {
                if ($bitmapPresent !== 0.0 || $numberOfMissing !== 0.0 || abs($value - $missingValue) < 0.000001 || $value < 0 || $value > 1) {
                    return null;
                }
                $values[$parameterCode] = round($value * 100, 6);
            }
        }
        if (array_keys($values) !== array_keys(self::PARAMETERS)) {
            return null;
        }

        return [
            'cloud_cover_pct' => (float) $values[71],
            'cloud_cover_low_pct' => (float) $values[73],
            'cloud_cover_mid_pct' => (float) $values[74],
            'cloud_cover_high_pct' => (float) $values[75],
            'cloud_base_m' => is_float($values[186]) ? $values[186] : null,
        ];
    }

    private function timesMatch(int $date, int $time, CarbonImmutable $expected): bool
    {
        if ($date < 20000101 || $date > 21001231 || $time < 0 || $time > 2359 || $time % 100 > 59) {
            return false;
        }

        return sprintf('%08d%04d', $date, $time) === $expected->utc()->format('YmdHi');
    }

    /** @param list<array<string, float|null>> $points */
    private function average(array $points, string $key): float
    {
        return array_sum(array_column($points, $key)) / count($points);
    }

    /** @return array<string, mixed> */
    private function unavailable(string $note, bool $stale = false): array
    {
        return [
            'stale' => $stale,
            'complete' => false,
            'source' => ['name' => 'KNMI HARMONIE P1', 'url' => self::SOURCE_URL],
            'measured_at' => null,
            'model_run_at' => null,
            'valid_at' => null,
            'sample_count' => 0,
            'cloud_base_m' => null,
            'cloud_base_sample_count' => 0,
            'availability_note' => $note,
        ];
    }

    private function validCoordinates(float $latitude, float $longitude): bool
    {
        return is_finite($latitude) && is_finite($longitude)
            && $latitude >= 50.0 && $latitude <= 54.5
            && $longitude >= 2.5 && $longitude <= 8.0;
    }

    private function positiveConfig(string $key, int $default, int $minimum, int $maximum): int
    {
        $value = (int) config('dis.knmi_forecast.'.$key, $default);

        return max($minimum, min($maximum, $value));
    }
}
