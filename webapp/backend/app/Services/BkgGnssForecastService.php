<?php

namespace App\Services;

use App\Contracts\GnssForecastProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Throwable;

/**
 * Predicts open-sky GPS/Galileo availability from the official IGS daily
 * merged broadcast ephemeris hosted by BKG. The gzip and RINEX documents are
 * parsed in bounded memory and are never written to local or temporary files.
 * Production shares validated source and calculation data through Laravel's
 * Redis-backed cache.
 *
 * This is a planning source. It deliberately does not claim to measure which
 * satellites a physical receiver sees or uses in a fix.
 */
final class BkgGnssForecastService implements GnssForecastProvider
{
    public const MAX_COMPRESSED_BYTES = 3_145_728;

    public const MAX_RINEX_BYTES = 12_582_912;

    private const BASE_URL = 'https://igs.bkg.bund.de/root_ftp/IGS/BRDC';

    private const CACHE_NAMESPACE = 'wallboard:uav-forecast:gnss-brdc:v1';

    private const CALCULATION_BUCKET_SECONDS = 300;

    private const MAX_LOCATIONS = 12;

    private const MAX_HEADER_LINES = 256;

    private const MAX_RINEX_LINES = 150_000;

    private const MAX_NAVIGATION_RECORDS = 20_000;

    private const MAX_RECORDS_PER_SATELLITE = 256;

    private const MAX_SELECTED_SATELLITES = 96;

    private const DEFLATE_INPUT_CHUNK_BYTES = 1024;

    private const LOCK_POLL_MICROSECONDS = 100_000;

    private const GPS_GRAVITATIONAL_CONSTANT = 3.986005e14;

    private const GALILEO_GRAVITATIONAL_CONSTANT = 3.986004418e14;

    private const EARTH_ROTATION_RATE = 7.2921151467e-5;

    private const WGS84_SEMI_MAJOR_AXIS = 6_378_137.0;

    private const WGS84_ECCENTRICITY_SQUARED = 6.69437999014e-3;

    private const SOURCE_NAME = 'BKG / International GNSS Service (IGS)';

    private const PROVENANCE = 'Server-side open-sky planning from healthy, current GPS and Galileo broadcast ephemerides. Visibility and usability are predictions, not receiver measurements or a confirmed fix.';

    /**
     * @param  array<string, mixed>  $resolution
     * @return array{
     *     complete: bool,
     *     stale: bool,
     *     measured_at: string,
     *     location_count: int,
     *     elevation_mask_deg: float,
     *     counts: array{
     *         visible: int|null,
     *         usable: int|null,
     *         visible_by_constellation: array{gps: int|null, galileo: int|null},
     *         usable_by_constellation: array{gps: int|null, galileo: int|null}
     *     },
     *     pdop: array{
     *         value: float|null,
     *         complete: bool,
     *         geometry_sufficient: bool|null,
     *         sample_count: int,
     *         value_sample_count: int
     *     },
     *     ephemeris: array{satellite_count: int|null, gps: int|null, galileo: int|null, maximum_age_seconds: int|null},
     *     source: array{
     *         name: string,
     *         url: string|null,
     *         urls: list<string>,
     *         file_dates: list<string>,
     *         fetched_at: string|null,
     *         format: string,
     *         attribution: string,
     *         terms_url: string
     *     },
     *     provenance: string
     * }
     */
    public function forResolution(array $resolution): array
    {
        $measuredAt = $this->calculationBucket(CarbonImmutable::now('UTC'));
        $locations = $this->validatedLocations($resolution);
        $mask = $this->elevationMask();
        if ($locations === null) {
            return $this->failureResult($measuredAt, 0, $mask);
        }

        try {
            $cacheKey = $this->calculationCacheKey($locations, $measuredAt, $mask);
            $cached = Cache::get($cacheKey);
            if ($this->isCachedResult($cached, $measuredAt, count($locations), $mask)) {
                return $cached;
            }

            $lock = Cache::lock(
                $cacheKey.':lock',
                $this->positiveConfig('gnss_lock_seconds', 45, 15, 90),
            );
            $waited = $this->waitForLock($lock, function () use (
                $cacheKey,
                $measuredAt,
                $locations,
                $mask,
            ): ?array {
                $waitingCached = Cache::get($cacheKey);

                return $this->isCachedResult($waitingCached, $measuredAt, count($locations), $mask)
                    ? $waitingCached
                    : null;
            });
            if (! $waited['acquired']) {
                return $waited['cached']
                    ?? $this->failureResult($measuredAt, count($locations), $mask);
            }

            try {
                $cached = Cache::get($cacheKey);
                if ($this->isCachedResult($cached, $measuredAt, count($locations), $mask)) {
                    return $cached;
                }

                $result = $this->calculateWithDailyFallback($locations, $measuredAt, $mask);
                Cache::put(
                    $cacheKey,
                    $result,
                    $result['complete']
                        ? $this->positiveConfig('gnss_calculation_cache_seconds', 300, 60, 900)
                        : $this->positiveConfig('gnss_failure_cache_seconds', 30, 10, 120),
                );

                return $result;
            } finally {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // A short-lived calculation cache remains safe if the
                    // distributed lock expires while this process finishes.
                }
            }
        } catch (Throwable) {
            return $this->failureResult($measuredAt, count($locations), $mask);
        }
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float, altitude_m: float}>  $locations
     * @return array<string, mixed>
     */
    private function calculateWithDailyFallback(
        array $locations,
        CarbonImmutable $measuredAt,
        float $mask,
    ): array {
        $sources = [];
        $currentDate = $measuredAt->startOfDay();
        $current = $this->sourceForDate($currentDate);
        if ($current !== null) {
            $sources[] = $current;
            $result = $this->calculate($locations, $measuredAt, $mask, $sources);
            if ($result['complete'] === true && $result['pdop']['complete'] === true) {
                return $result;
            }
        }

        $previous = $this->sourceForDate($currentDate->subDay());
        if ($previous !== null) {
            $sources[] = $previous;
        }

        if ($sources === []) {
            return $this->failureResult($measuredAt, count($locations), $mask);
        }

        return $this->calculate($locations, $measuredAt, $mask, $sources);
    }

    /**
     * @return array{
     *     source_date: string,
     *     url: string,
     *     fetched_at: string,
     *     rinex_version: string,
     *     ephemerides: list<array<string, int|float|string>>
     * }|null
     */
    private function sourceForDate(CarbonImmutable $date): ?array
    {
        $date = $date->utc()->startOfDay();
        $url = $this->sourceUrl($date);
        $sourceDate = $date->toDateString();
        $cacheKey = self::CACHE_NAMESPACE.':source:'.$date->format('Ymd');
        $cached = Cache::get($cacheKey);
        if ($this->isCachedSource(
            $cached,
            $sourceDate,
            $url,
            $this->sourceCacheValidationSeconds(),
        )) {
            return $cached;
        }

        $lock = Cache::lock(
            $cacheKey.':lock',
            $this->positiveConfig('gnss_lock_seconds', 45, 15, 90),
        );
        $waited = $this->waitForLock($lock, function () use ($cacheKey, $sourceDate, $url): ?array {
            $waitingCached = Cache::get($cacheKey);

            return $this->isCachedSource(
                $waitingCached,
                $sourceDate,
                $url,
                $this->sourceCacheValidationSeconds(),
            )
                ? $waitingCached
                : null;
        });
        if (! $waited['acquired']) {
            if ($waited['cached'] !== null) {
                return $waited['cached'];
            }

            $lastGood = $this->lastGoodSource($cacheKey, $sourceDate, $url);
            if ($lastGood !== null) {
                return $lastGood;
            }

            // Do not mistake an in-flight current-day fetch for a missing file
            // and immediately issue an unnecessary previous-day request.
            throw new \RuntimeException('Timed out waiting for the shared GNSS source fetch.');
        }

        try {
            $cached = Cache::get($cacheKey);
            if ($this->isCachedSource(
                $cached,
                $sourceDate,
                $url,
                $this->sourceCacheValidationSeconds(),
            )) {
                return $cached;
            }

            $source = $this->downloadSource($date, $url);
            Cache::put(
                $cacheKey,
                $source,
                $this->positiveConfig('gnss_source_cache_seconds', 900, 300, 3600),
            );
            try {
                Cache::put(
                    $cacheKey.':last-good',
                    $source,
                    $this->positiveConfig('gnss_last_good_cache_seconds', 21600, 900, 43200),
                );
            } catch (Throwable) {
                // The just-validated live source remains usable for this
                // request even if the longer-lived resilience write fails.
            }

            return $source;
        } catch (Throwable) {
            return $this->lastGoodSource($cacheKey, $sourceDate, $url);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Source data is immutable for its dated URL and additionally
                // validated after every cache read.
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function lastGoodSource(string $cacheKey, string $sourceDate, string $url): ?array
    {
        $lastGood = Cache::get($cacheKey.':last-good');
        $maximumAge = $this->positiveConfig(
            'gnss_last_good_cache_seconds',
            21600,
            900,
            43200,
        );

        return $this->isCachedSource($lastGood, $sourceDate, $url, $maximumAge)
            ? $lastGood
            : null;
    }

    private function sourceCacheValidationSeconds(): int
    {
        return $this->positiveConfig('gnss_source_cache_seconds', 900, 300, 3600) + 60;
    }

    /**
     * Download into a RAM-only stream. Content-Length rejects known oversized
     * responses before their bodies are transferred; the progress callback
     * aborts chunked or dishonest responses once they cross the same bound.
     *
     * @return array{
     *     source_date: string,
     *     url: string,
     *     fetched_at: string,
     *     rinex_version: string,
     *     ephemerides: list<array<string, int|float|string>>
     * }
     */
    private function downloadSource(CarbonImmutable $date, string $url): array
    {
        $memory = fopen('php://memory', 'w+b');
        if ($memory === false) {
            throw new \RuntimeException('Unable to allocate the bounded GNSS memory stream.');
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/gzip, application/x-gzip, application/octet-stream',
                'Accept-Encoding' => 'identity',
                'User-Agent' => 'DIS-UAV-GNSS-Planner/1.0',
            ])->connectTimeout(
                $this->positiveConfig('gnss_connect_timeout_seconds', 3, 1, 10),
            )->timeout(
                $this->positiveConfig('gnss_timeout_seconds', 12, 3, 30),
            )->withOptions([
                'allow_redirects' => false,
                'decode_content' => false,
                'sink' => $memory,
                'on_headers' => static function (PsrResponseInterface $response): void {
                    self::assertBoundedTransferLength($response->getHeaderLine('Content-Length'));
                },
                'progress' => static function (
                    int $downloadTotal,
                    int $downloadedBytes,
                    int $uploadTotal,
                    int $uploadedBytes,
                ): void {
                    unset($uploadTotal, $uploadedBytes);
                    if ($downloadTotal > self::MAX_COMPRESSED_BYTES
                        || $downloadedBytes > self::MAX_COMPRESSED_BYTES) {
                        throw new \RuntimeException('BKG gzip transfer exceeded its memory bound.');
                    }
                },
            ])->get($url);

            if (! rewind($memory)) {
                throw new \RuntimeException('Unable to rewind the GNSS memory stream.');
            }
            $gzip = stream_get_contents($memory, self::MAX_COMPRESSED_BYTES + 1);
            if (! is_string($gzip)) {
                throw new \RuntimeException('Unable to read the GNSS memory stream.');
            }

            return $this->parseResponse($response, $gzip, $date, $url);
        } finally {
            if (is_resource($memory)) {
                fclose($memory);
            }
        }
    }

    private static function assertBoundedTransferLength(string $contentLength): void
    {
        $contentLength = trim($contentLength);
        if ($contentLength !== ''
            && (preg_match('/\A\d+\z/D', $contentLength) !== 1
                || (int) $contentLength > self::MAX_COMPRESSED_BYTES)) {
            throw new \RuntimeException('BKG response length is invalid.');
        }
    }

    /**
     * @param  callable(): ?array<string, mixed>  $readCached
     * @return array{acquired: bool, cached: array<string, mixed>|null}
     */
    private function waitForLock(Lock $lock, callable $readCached): array
    {
        $waitMilliseconds = $this->positiveConfig(
            'gnss_lock_wait_milliseconds',
            5_000,
            100,
            10_000,
        );
        $deadline = hrtime(true) + ($waitMilliseconds * 1_000_000);

        do {
            if ($lock->get()) {
                return ['acquired' => true, 'cached' => null];
            }

            $cached = $readCached();
            if (is_array($cached)) {
                return ['acquired' => false, 'cached' => $cached];
            }

            $remainingNanoseconds = $deadline - hrtime(true);
            if ($remainingNanoseconds <= 0) {
                return ['acquired' => false, 'cached' => null];
            }
            usleep((int) min(
                self::LOCK_POLL_MICROSECONDS,
                (int) ceil($remainingNanoseconds / 1000),
            ));
        } while (true);
    }

    /**
     * @return array{
     *     source_date: string,
     *     url: string,
     *     fetched_at: string,
     *     rinex_version: string,
     *     ephemerides: list<array<string, int|float|string>>
     * }
     */
    private function parseResponse(
        Response $response,
        string $gzip,
        CarbonImmutable $date,
        string $url,
    ): array {
        if ($response->status() !== 200
            || $response->redirect()
            || trim((string) $response->header('Location')) !== '') {
            throw new \RuntimeException('BKG returned an unexpected HTTP response.');
        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! in_array($contentType, [
            'application/gzip',
            'application/x-gzip',
            'application/octet-stream',
        ], true)) {
            throw new \RuntimeException('BKG returned an unexpected content type.');
        }

        self::assertBoundedTransferLength((string) $response->header('Content-Length'));
        if (strlen($gzip) < 32
            || strlen($gzip) > self::MAX_COMPRESSED_BYTES
            || ! str_starts_with($gzip, "\x1f\x8b")) {
            throw new \RuntimeException('BKG returned an invalid gzip document.');
        }

        $parsed = $this->parseRinex($this->decompressGzip($gzip), $date);

        return [
            'source_date' => $date->toDateString(),
            'url' => $url,
            'fetched_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:sP'),
            'rinex_version' => $parsed['version'],
            'ephemerides' => $parsed['ephemerides'],
        ];
    }

    private function decompressGzip(string $gzip): string
    {
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflate === false) {
            throw new \RuntimeException('Unable to initialize the gzip decoder.');
        }

        $output = '';
        $length = strlen($gzip);
        for ($offset = 0; $offset < $length; $offset += self::DEFLATE_INPUT_CHUNK_BYTES) {
            $chunk = substr($gzip, $offset, self::DEFLATE_INPUT_CHUNK_BYTES);
            $last = $offset + strlen($chunk) >= $length;
            $decoded = inflate_add($inflate, $chunk, $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($decoded === false || strlen($output) + strlen($decoded) > self::MAX_RINEX_BYTES) {
                throw new \RuntimeException('BKG gzip decompression failed or exceeded its bound.');
            }
            $output .= $decoded;
        }

        if (inflate_get_status($inflate) !== ZLIB_STREAM_END
            || inflate_get_read_len($inflate) !== $length
            || $output === '') {
            throw new \RuntimeException('BKG gzip stream is incomplete or contains trailing data.');
        }

        return $output;
    }

    /**
     * @return array{version: string, ephemerides: list<array<string, int|float|string>>}
     */
    private function parseRinex(string $rinex, CarbonImmutable $sourceDate): array
    {
        if (strlen($rinex) > self::MAX_RINEX_BYTES
            || preg_match('/[^\x0A\x0D\x20-\x7E]/', $rinex) === 1) {
            throw new \UnexpectedValueException('RINEX contains invalid bytes.');
        }

        $normalized = str_replace("\r\n", "\n", $rinex);
        if (str_contains($normalized, "\r")) {
            throw new \UnexpectedValueException('RINEX uses inconsistent line endings.');
        }
        $lines = explode("\n", $normalized);
        if (end($lines) === '') {
            array_pop($lines);
        }
        if ($lines === [] || count($lines) > self::MAX_RINEX_LINES) {
            throw new \UnexpectedValueException('RINEX line count is invalid.');
        }

        $first = $lines[0];
        if (strlen($first) < 80
            || trim(substr($first, 60, 20)) !== 'RINEX VERSION / TYPE'
            || trim(substr($first, 20, 20)) !== 'N: GNSS NAV DATA'
            || ! in_array(trim(substr($first, 40, 20)), ['M: MIXED', 'M'], true)) {
            throw new \UnexpectedValueException('RINEX is not mixed GNSS navigation data.');
        }
        $versionNumber = trim(substr($first, 0, 9));
        if ($versionNumber !== '3.05') {
            throw new \UnexpectedValueException('Only the validated RINEX 3.05 layout is accepted.');
        }

        $dataStart = null;
        $headerLimit = min(count($lines), self::MAX_HEADER_LINES);
        for ($index = 0; $index < $headerLimit; $index++) {
            if (strlen($lines[$index]) > 120 || strlen($lines[$index]) < 60) {
                throw new \UnexpectedValueException('RINEX header line length is invalid.');
            }
            if (trim(substr(str_pad($lines[$index], 80), 60, 20)) === 'END OF HEADER') {
                $dataStart = $index + 1;
                break;
            }
        }
        if ($dataStart === null || $dataStart >= count($lines)) {
            throw new \UnexpectedValueException('RINEX header is incomplete.');
        }

        $ephemerides = [];
        $perSatellite = [];
        $navigationRecords = 0;
        for ($index = $dataStart; $index < count($lines);) {
            $line = $lines[$index];
            if ($line === '') {
                $index++;

                continue;
            }
            if (strlen($line) > 120 || preg_match('/\A([GRECJIS])\d{2}/', $line, $match) !== 1) {
                throw new \UnexpectedValueException('RINEX navigation record identifier is invalid.');
            }

            $constellationCode = $match[1];
            $recordLines = match ($constellationCode) {
                'R' => 5,
                'S' => 4,
                default => 8,
            };
            if ($index + $recordLines > count($lines)) {
                throw new \UnexpectedValueException('RINEX navigation record is truncated.');
            }
            for ($continuation = 1; $continuation < $recordLines; $continuation++) {
                $continuationLine = $lines[$index + $continuation];
                if (strlen($continuationLine) > 120
                    || strlen($continuationLine) < 4
                    || substr($continuationLine, 0, 4) !== '    ') {
                    throw new \UnexpectedValueException('RINEX continuation line is invalid.');
                }
            }

            $navigationRecords++;
            if ($navigationRecords > self::MAX_NAVIGATION_RECORDS) {
                throw new \UnexpectedValueException('RINEX contains too many navigation records.');
            }

            if (in_array($constellationCode, ['G', 'E'], true)) {
                $record = $this->parseKeplerRecord(
                    array_slice($lines, $index, 8),
                    $sourceDate,
                );
                $satellite = (string) $record['satellite_id'];
                $perSatellite[$satellite] = ($perSatellite[$satellite] ?? 0) + 1;
                if ($perSatellite[$satellite] > self::MAX_RECORDS_PER_SATELLITE) {
                    throw new \UnexpectedValueException('RINEX contains too many records for one satellite.');
                }
                $ephemerides[] = $record;
            }
            $index += $recordLines;
        }

        if ($ephemerides === []) {
            throw new \UnexpectedValueException('RINEX contains no GPS or Galileo ephemerides.');
        }

        return ['version' => $versionNumber, 'ephemerides' => $ephemerides];
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, int|float|string>
     */
    private function parseKeplerRecord(array $lines, CarbonImmutable $sourceDate): array
    {
        $first = str_pad($lines[0], 80);
        $satellite = substr($first, 0, 3);
        if (preg_match('/\A[GE]\d{2}\z/D', $satellite) !== 1) {
            throw new \UnexpectedValueException('GPS/Galileo satellite identifier is invalid.');
        }

        $epochParts = preg_split('/\s+/', trim(substr($first, 3, 20)));
        if (! is_array($epochParts) || count($epochParts) !== 6) {
            throw new \UnexpectedValueException('RINEX ephemeris epoch is invalid.');
        }
        foreach ($epochParts as $part) {
            if (preg_match('/\A\d+(?:\.\d+)?\z/D', $part) !== 1) {
                throw new \UnexpectedValueException('RINEX ephemeris epoch is malformed.');
            }
        }

        [$year, $month, $day, $hour, $minute] = array_map('intval', array_slice($epochParts, 0, 5));
        $seconds = (float) $epochParts[5];
        if ($year < 1980 || $year > 2100
            || ! checkdate($month, $day, $year)
            || $hour < 0 || $hour > 23
            || $minute < 0 || $minute > 59
            || $seconds < 0 || $seconds >= 60) {
            throw new \UnexpectedValueException('RINEX ephemeris date is outside valid bounds.');
        }
        $wholeSeconds = (int) floor($seconds);
        $microseconds = (int) round(($seconds - $wholeSeconds) * 1_000_000);
        $toc = CarbonImmutable::create(
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $wholeSeconds,
            'UTC',
        )->addMicroseconds($microseconds);
        if (abs($sourceDate->startOfDay()->diffInDays($toc->startOfDay(), false)) > 2) {
            throw new \UnexpectedValueException('RINEX ephemeris date does not match its daily file.');
        }

        for ($field = 0; $field < 3; $field++) {
            $this->requiredNumber(substr($first, 23 + ($field * 19), 19));
        }
        $second = $this->continuationValues($lines[1]);
        $third = $this->continuationValues($lines[2]);
        $fourth = $this->continuationValues($lines[3]);
        $fifth = $this->continuationValues($lines[4]);
        $sixth = $this->continuationValues($lines[5]);
        $seventh = $this->continuationValues($lines[6]);

        $week = $this->integerNumber($sixth[2]);
        $health = $this->integerNumber($seventh[1]);
        $toeSeconds = $this->requiredFinite($fourth[0]);
        $record = [
            'satellite_id' => $satellite,
            'constellation' => $satellite[0] === 'G' ? 'gps' : 'galileo',
            'toc_unix' => (float) $toc->format('U.u'),
            'toe_seconds' => $toeSeconds,
            'toe_unix' => $this->toeTimestamp((float) $toc->format('U.u'), $toeSeconds),
            'week' => $week,
            'crs' => $this->requiredFinite($second[1]),
            'delta_n' => $this->requiredFinite($second[2]),
            'm0' => $this->requiredFinite($second[3]),
            'cuc' => $this->requiredFinite($third[0]),
            'eccentricity' => $this->requiredFinite($third[1]),
            'cus' => $this->requiredFinite($third[2]),
            'sqrt_a' => $this->requiredFinite($third[3]),
            'cic' => $this->requiredFinite($fourth[1]),
            'omega0' => $this->requiredFinite($fourth[2]),
            'cis' => $this->requiredFinite($fourth[3]),
            'i0' => $this->requiredFinite($fifth[0]),
            'crc' => $this->requiredFinite($fifth[1]),
            'omega' => $this->requiredFinite($fifth[2]),
            'omega_dot' => $this->requiredFinite($fifth[3]),
            'idot' => $this->requiredFinite($sixth[0]),
            'data_sources' => $this->integerNumber($sixth[1]),
            'health' => $health,
            'source_date' => $sourceDate->toDateString(),
        ];
        if (! $this->validEphemeris($record)) {
            throw new \UnexpectedValueException('RINEX ephemeris values are outside physical bounds.');
        }

        return $record;
    }

    /** @return list<float|null> */
    private function continuationValues(string $line): array
    {
        $line = str_pad($line, 80);
        $values = [];
        for ($field = 0; $field < 4; $field++) {
            $raw = substr($line, 4 + ($field * 19), 19);
            $values[] = trim($raw) === '' ? null : $this->requiredNumber($raw);
        }

        return $values;
    }

    private function requiredNumber(string $raw): float
    {
        $value = trim($raw);
        if (preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)[DEde][+-]\d{2,3}\z/D', $value) !== 1) {
            throw new \UnexpectedValueException('RINEX numeric field is malformed.');
        }
        $number = (float) str_ireplace('D', 'E', $value);
        if (! is_finite($number)) {
            throw new \UnexpectedValueException('RINEX numeric field is not finite.');
        }

        return $number;
    }

    private function requiredFinite(?float $value): float
    {
        if ($value === null || ! is_finite($value)) {
            throw new \UnexpectedValueException('Required RINEX field is missing.');
        }

        return $value;
    }

    private function integerNumber(?float $value): int
    {
        $value = $this->requiredFinite($value);
        if (abs($value - round($value)) > 1e-6) {
            throw new \UnexpectedValueException('RINEX integer field is malformed.');
        }

        return (int) round($value);
    }

    private function toeTimestamp(float $tocUnix, float $toeSeconds): float
    {
        if ($toeSeconds < 0 || $toeSeconds >= 604_800) {
            throw new \UnexpectedValueException('RINEX time of ephemeris is invalid.');
        }
        $toc = CarbonImmutable::createFromTimestampUTC((int) floor($tocUnix));
        $weekStart = $toc->startOfDay()->subDays($toc->dayOfWeek)->getTimestamp();
        $candidate = $weekStart + $toeSeconds;
        while ($candidate - $tocUnix > 302_400) {
            $candidate -= 604_800;
        }
        while ($tocUnix - $candidate > 302_400) {
            $candidate += 604_800;
        }

        return $candidate;
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float, altitude_m: float}>  $locations
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function calculate(
        array $locations,
        CarbonImmutable $measuredAt,
        float $mask,
        array $sources,
    ): array {
        $selected = $this->selectEphemerides($sources, $measuredAt);
        $minimum = $this->positiveConfig('gnss_min_ephemerides_per_constellation', 12, 4, 24);
        $selectedCounts = $this->constellationCounts($selected);
        if ($selectedCounts['gps'] < $minimum || $selectedCounts['galileo'] < $minimum) {
            return $this->failureResult(
                $measuredAt,
                count($locations),
                $mask,
                $this->sourceMetadata($sources, $selected),
            );
        }

        $positions = [];
        $atUnix = $this->gnssSystemTimestamp($measuredAt);
        foreach ($selected as $ephemeris) {
            $position = $this->satellitePosition($ephemeris, $atUnix);
            if ($position !== null) {
                $positions[] = [
                    'satellite_id' => $ephemeris['satellite_id'],
                    'constellation' => $ephemeris['constellation'],
                    'x' => $position['x'],
                    'y' => $position['y'],
                    'z' => $position['z'],
                ];
            }
        }
        $positionCounts = $this->constellationCounts($positions);
        if ($positionCounts['gps'] < $minimum || $positionCounts['galileo'] < $minimum) {
            return $this->failureResult(
                $measuredAt,
                count($locations),
                $mask,
                $this->sourceMetadata($sources, $selected),
            );
        }

        $visibleWorst = null;
        $usableWorst = null;
        $pdops = [];
        foreach ($locations as $location) {
            $observer = $this->observerGeometry($location);
            $visible = 0;
            $usable = 0;
            $visibleForLocation = ['gps' => 0, 'galileo' => 0];
            $usableForLocation = ['gps' => 0, 'galileo' => 0];
            $designRows = [];
            foreach ($positions as $position) {
                $geometry = $this->topocentricGeometry($observer, $position);
                if ($geometry === null || $geometry['elevation_deg'] <= 0.0) {
                    continue;
                }
                $constellation = $position['constellation'];
                $visible++;
                $visibleForLocation[$constellation]++;
                if ($mask > $geometry['elevation_deg'] + 1e-9) {
                    continue;
                }
                $usable++;
                $usableForLocation[$constellation]++;
                $designRows[] = [
                    -$geometry['east_unit'],
                    -$geometry['north_unit'],
                    -$geometry['up_unit'],
                    $constellation === 'gps' ? 1.0 : 0.0,
                    $constellation === 'galileo' ? 1.0 : 0.0,
                ];
            }

            $tieBreaker = mb_strtolower($location['label']).sprintf(
                '|%+.7f|%+.7f',
                $location['latitude'],
                $location['longitude'],
            );
            if ($visibleWorst === null
                || $visible < $visibleWorst['total']
                || ($visible === $visibleWorst['total'] && strcmp($tieBreaker, $visibleWorst['tie']) < 0)) {
                $visibleWorst = [
                    'total' => $visible,
                    'by_constellation' => $visibleForLocation,
                    'tie' => $tieBreaker,
                ];
            }
            if ($usableWorst === null
                || $usable < $usableWorst['total']
                || ($usable === $usableWorst['total'] && strcmp($tieBreaker, $usableWorst['tie']) < 0)) {
                $usableWorst = [
                    'total' => $usable,
                    'by_constellation' => $usableForLocation,
                    'tie' => $tieBreaker,
                ];
            }
            $pdop = $this->combinedPdop($designRows);
            if ($pdop !== null) {
                $pdops[] = $pdop;
            }
        }

        $pdopGeometrySufficient = count($pdops) === count($locations);
        $ages = array_map(
            static fn (array $record): float => abs($atUnix - (float) $record['toe_unix']),
            $selected,
        );

        return [
            'complete' => true,
            'stale' => false,
            'measured_at' => $measuredAt->format('Y-m-d\TH:i:sP'),
            'location_count' => count($locations),
            'elevation_mask_deg' => $mask,
            'counts' => [
                'visible' => $visibleWorst['total'],
                'usable' => $usableWorst['total'],
                'visible_by_constellation' => $visibleWorst['by_constellation'],
                'usable_by_constellation' => $usableWorst['by_constellation'],
            ],
            'pdop' => [
                'value' => $pdopGeometrySufficient ? round(max($pdops), 2) : null,
                'complete' => true,
                'geometry_sufficient' => $pdopGeometrySufficient,
                'sample_count' => count($locations),
                'value_sample_count' => count($pdops),
            ],
            'ephemeris' => [
                'satellite_count' => count($selected),
                'gps' => $selectedCounts['gps'],
                'galileo' => $selectedCounts['galileo'],
                'maximum_age_seconds' => (int) ceil(max($ages)),
            ],
            'source' => $this->sourceMetadata($sources, $selected),
            'provenance' => self::PROVENANCE,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, int|float|string>>
     */
    private function selectEphemerides(array $sources, CarbonImmutable $measuredAt): array
    {
        $atUnix = $this->gnssSystemTimestamp($measuredAt);
        $maximumAge = $this->positiveConfig('gnss_ephemeris_max_age_seconds', 14400, 3600, 21600);
        $futureTolerance = $this->positiveConfig('gnss_ephemeris_future_tolerance_seconds', 1800, 0, 7200);
        $selected = [];
        foreach ($sources as $source) {
            foreach ((array) ($source['ephemerides'] ?? []) as $record) {
                if (! is_array($record)
                    || ! $this->validEphemeris($record)
                    || ! $this->isHealthy($record)) {
                    continue;
                }
                $toeDelta = $atUnix - (float) $record['toe_unix'];
                $tocDelta = $atUnix - (float) $record['toc_unix'];
                if (abs($toeDelta) > $maximumAge
                    || $toeDelta < -$futureTolerance
                    || abs($tocDelta) > $maximumAge + 7200
                    || $tocDelta < -$futureTolerance) {
                    continue;
                }

                $satellite = (string) $record['satellite_id'];
                $record['source_date'] = (string) $source['source_date'];
                $existing = $selected[$satellite] ?? null;
                if (! is_array($existing)
                    || abs($toeDelta) < abs($atUnix - (float) $existing['toe_unix'])
                    || (abs($toeDelta) === abs($atUnix - (float) $existing['toe_unix'])
                        && (float) $record['toc_unix'] > (float) $existing['toc_unix'])) {
                    $selected[$satellite] = $record;
                }
            }
        }

        ksort($selected);
        if (count($selected) > self::MAX_SELECTED_SATELLITES) {
            return [];
        }

        return array_values($selected);
    }

    /** @param array<string, mixed> $record */
    private function validEphemeris(array $record): bool
    {
        $numericKeys = [
            'toc_unix', 'toe_seconds', 'toe_unix', 'crs', 'delta_n', 'm0',
            'cuc', 'eccentricity', 'cus', 'sqrt_a', 'cic', 'omega0', 'cis',
            'i0', 'crc', 'omega', 'omega_dot', 'idot',
        ];
        foreach ($numericKeys as $key) {
            if (! is_int($record[$key] ?? null) && ! is_float($record[$key] ?? null)) {
                return false;
            }
            if (! is_finite((float) $record[$key])) {
                return false;
            }
        }
        if (! is_int($record['week'] ?? null)
            || ! is_int($record['health'] ?? null)
            || ! is_int($record['data_sources'] ?? null)
            || ! is_string($record['satellite_id'] ?? null)
            || preg_match('/\A[GE]\d{2}\z/D', $record['satellite_id']) !== 1
            || ! in_array($record['constellation'] ?? null, ['gps', 'galileo'], true)) {
            return false;
        }

        return (float) $record['toe_seconds'] >= 0
            && (float) $record['toe_seconds'] < 604_800
            && $record['week'] >= 0
            && $record['health'] >= 0
            && $record['health'] <= 511
            && $record['data_sources'] >= 0
            && (float) $record['sqrt_a'] >= 4_000
            && (float) $record['sqrt_a'] <= 6_500
            && (float) $record['eccentricity'] >= 0
            && (float) $record['eccentricity'] < 0.2
            && (float) $record['i0'] >= 0.5
            && (float) $record['i0'] <= 1.5
            && abs((float) $record['delta_n']) <= 1e-6
            && abs((float) $record['omega_dot']) <= 1e-5
            && abs((float) $record['idot']) <= 1e-6
            && abs((float) $record['cuc']) <= 0.1
            && abs((float) $record['cus']) <= 0.1
            && abs((float) $record['cic']) <= 0.1
            && abs((float) $record['cis']) <= 0.1
            && abs((float) $record['crs']) <= 10_000
            && abs((float) $record['crc']) <= 10_000;
    }

    /** @param array<string, mixed> $record */
    private function isHealthy(array $record): bool
    {
        if (($record['constellation'] ?? null) === 'gps') {
            return (int) $record['health'] === 0;
        }

        // RINEX 3 encodes Galileo signal health in one bit field. For an E1
        // planning result, accept I/NAV data carrying E1-B (data-source bit 0
        // or 2) only when the E1-B DVS and two HS bits (health 0..2) are clear.
        $hasE1BroadcastData = (((int) $record['data_sources']) & 0b101) !== 0;
        $e1HealthIsGood = (((int) $record['health']) & 0b111) === 0;

        return $hasE1BroadcastData && $e1HealthIsGood;
    }

    /**
     * @param  array<string, int|float|string>  $ephemeris
     * @return array{x: float, y: float, z: float}|null
     */
    private function satellitePosition(array $ephemeris, float $atUnix): ?array
    {
        $tk = $atUnix - (float) $ephemeris['toe_unix'];
        while ($tk > 302_400) {
            $tk -= 604_800;
        }
        while ($tk < -302_400) {
            $tk += 604_800;
        }

        $a = (float) $ephemeris['sqrt_a'] ** 2;
        $mu = $ephemeris['constellation'] === 'gps'
            ? self::GPS_GRAVITATIONAL_CONSTANT
            : self::GALILEO_GRAVITATIONAL_CONSTANT;
        $meanMotion = sqrt($mu / ($a ** 3)) + (float) $ephemeris['delta_n'];
        $meanAnomaly = $this->normalizeAngle((float) $ephemeris['m0'] + ($meanMotion * $tk));
        $eccentricity = (float) $ephemeris['eccentricity'];
        $eccentricAnomaly = $meanAnomaly;
        $converged = false;
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $next = $meanAnomaly + ($eccentricity * sin($eccentricAnomaly));
            if (abs($next - $eccentricAnomaly) < 1e-13) {
                $eccentricAnomaly = $next;
                $converged = true;
                break;
            }
            $eccentricAnomaly = $next;
        }
        if (! $converged) {
            return null;
        }

        $trueAnomaly = atan2(
            sqrt(1 - ($eccentricity ** 2)) * sin($eccentricAnomaly),
            cos($eccentricAnomaly) - $eccentricity,
        );
        $argument = $trueAnomaly + (float) $ephemeris['omega'];
        $twiceArgument = 2 * $argument;
        $u = $argument
            + ((float) $ephemeris['cus'] * sin($twiceArgument))
            + ((float) $ephemeris['cuc'] * cos($twiceArgument));
        $radius = ($a * (1 - ($eccentricity * cos($eccentricAnomaly))))
            + ((float) $ephemeris['crs'] * sin($twiceArgument))
            + ((float) $ephemeris['crc'] * cos($twiceArgument));
        $inclination = (float) $ephemeris['i0']
            + ((float) $ephemeris['idot'] * $tk)
            + ((float) $ephemeris['cis'] * sin($twiceArgument))
            + ((float) $ephemeris['cic'] * cos($twiceArgument));
        $orbitalX = $radius * cos($u);
        $orbitalY = $radius * sin($u);
        $ascendingNode = (float) $ephemeris['omega0']
            + (((float) $ephemeris['omega_dot'] - self::EARTH_ROTATION_RATE) * $tk)
            - (self::EARTH_ROTATION_RATE * (float) $ephemeris['toe_seconds']);
        $x = ($orbitalX * cos($ascendingNode))
            - ($orbitalY * cos($inclination) * sin($ascendingNode));
        $y = ($orbitalX * sin($ascendingNode))
            + ($orbitalY * cos($inclination) * cos($ascendingNode));
        $z = $orbitalY * sin($inclination);
        if (! is_finite($x) || ! is_finite($y) || ! is_finite($z)) {
            return null;
        }

        return ['x' => $x, 'y' => $y, 'z' => $z];
    }

    /**
     * @param  array{label: string, latitude: float, longitude: float, altitude_m: float}  $location
     * @return array<string, float>
     */
    private function observerGeometry(array $location): array
    {
        $latitude = deg2rad($location['latitude']);
        $longitude = deg2rad($location['longitude']);
        $sinLatitude = sin($latitude);
        $cosLatitude = cos($latitude);
        $sinLongitude = sin($longitude);
        $cosLongitude = cos($longitude);
        $primeVertical = self::WGS84_SEMI_MAJOR_AXIS
            / sqrt(1 - (self::WGS84_ECCENTRICITY_SQUARED * ($sinLatitude ** 2)));

        return [
            'x' => ($primeVertical + $location['altitude_m']) * $cosLatitude * $cosLongitude,
            'y' => ($primeVertical + $location['altitude_m']) * $cosLatitude * $sinLongitude,
            'z' => (($primeVertical * (1 - self::WGS84_ECCENTRICITY_SQUARED)) + $location['altitude_m']) * $sinLatitude,
            'sin_latitude' => $sinLatitude,
            'cos_latitude' => $cosLatitude,
            'sin_longitude' => $sinLongitude,
            'cos_longitude' => $cosLongitude,
        ];
    }

    /**
     * @param  array<string, float>  $observer
     * @param  array<string, int|float|string>  $satellite
     * @return array{elevation_deg: float, east_unit: float, north_unit: float, up_unit: float}|null
     */
    private function topocentricGeometry(array $observer, array $satellite): ?array
    {
        $dx = (float) $satellite['x'] - $observer['x'];
        $dy = (float) $satellite['y'] - $observer['y'];
        $dz = (float) $satellite['z'] - $observer['z'];
        $east = (-$observer['sin_longitude'] * $dx) + ($observer['cos_longitude'] * $dy);
        $north = (-$observer['sin_latitude'] * $observer['cos_longitude'] * $dx)
            - ($observer['sin_latitude'] * $observer['sin_longitude'] * $dy)
            + ($observer['cos_latitude'] * $dz);
        $up = ($observer['cos_latitude'] * $observer['cos_longitude'] * $dx)
            + ($observer['cos_latitude'] * $observer['sin_longitude'] * $dy)
            + ($observer['sin_latitude'] * $dz);
        $range = sqrt(($east ** 2) + ($north ** 2) + ($up ** 2));
        if (! is_finite($range) || $range < 1) {
            return null;
        }
        $upUnit = max(-1.0, min(1.0, $up / $range));

        return [
            'elevation_deg' => rad2deg(asin($upUnit)),
            'east_unit' => $east / $range,
            'north_unit' => $north / $range,
            'up_unit' => $upUnit,
        ];
    }

    /** @param list<list<float>> $rows */
    private function combinedPdop(array $rows): ?float
    {
        if (count($rows) < 5) {
            return null;
        }
        $hasGps = false;
        $hasGalileo = false;
        $normal = array_fill(0, 5, array_fill(0, 5, 0.0));
        foreach ($rows as $row) {
            if (count($row) !== 5) {
                return null;
            }
            $hasGps = $hasGps || $row[3] === 1.0;
            $hasGalileo = $hasGalileo || $row[4] === 1.0;
            for ($column = 0; $column < 5; $column++) {
                for ($other = 0; $other < 5; $other++) {
                    $normal[$column][$other] += $row[$column] * $row[$other];
                }
            }
        }
        if (! $hasGps || ! $hasGalileo) {
            return null;
        }

        $inverse = $this->invertMatrix($normal);
        if ($inverse === null) {
            return null;
        }
        $variance = $inverse[0][0] + $inverse[1][1] + $inverse[2][2];
        if (! is_finite($variance) || $variance <= 0) {
            return null;
        }
        $pdop = sqrt($variance);

        return is_finite($pdop) ? $pdop : null;
    }

    /**
     * @param  list<list<float>>  $matrix
     * @return list<list<float>>|null
     */
    private function invertMatrix(array $matrix): ?array
    {
        $size = count($matrix);
        if ($size !== 5) {
            return null;
        }
        $augmented = [];
        for ($row = 0; $row < $size; $row++) {
            if (count($matrix[$row]) !== $size) {
                return null;
            }
            $augmented[$row] = array_merge(
                array_map('floatval', $matrix[$row]),
                array_map(static fn (int $column): float => $column === $row ? 1.0 : 0.0, range(0, $size - 1)),
            );
        }

        for ($column = 0; $column < $size; $column++) {
            $pivotRow = $column;
            $pivotMagnitude = abs($augmented[$pivotRow][$column]);
            for ($candidate = $column + 1; $candidate < $size; $candidate++) {
                $magnitude = abs($augmented[$candidate][$column]);
                if ($magnitude > $pivotMagnitude) {
                    $pivotMagnitude = $magnitude;
                    $pivotRow = $candidate;
                }
            }
            if (! is_finite($pivotMagnitude) || $pivotMagnitude < 1e-10) {
                return null;
            }
            if ($pivotRow !== $column) {
                [$augmented[$column], $augmented[$pivotRow]] = [$augmented[$pivotRow], $augmented[$column]];
            }

            $pivot = $augmented[$column][$column];
            for ($entry = 0; $entry < $size * 2; $entry++) {
                $augmented[$column][$entry] /= $pivot;
            }
            for ($row = 0; $row < $size; $row++) {
                if ($row === $column) {
                    continue;
                }
                $factor = $augmented[$row][$column];
                for ($entry = 0; $entry < $size * 2; $entry++) {
                    $augmented[$row][$entry] -= $factor * $augmented[$column][$entry];
                }
            }
        }

        return array_map(
            static fn (array $row): array => array_slice($row, $size, $size),
            $augmented,
        );
    }

    /**
     * @param  array<string, mixed>  $resolution
     * @return list<array{label: string, latitude: float, longitude: float, altitude_m: float}>|null
     */
    private function validatedLocations(array $resolution): ?array
    {
        if (($resolution['complete'] ?? false) !== true
            || ! is_int($resolution['expected_locations'] ?? null)
            || $resolution['expected_locations'] < 1
            || $resolution['expected_locations'] > self::MAX_LOCATIONS
            || ! is_array($resolution['locations'] ?? null)
            || count($resolution['locations']) !== $resolution['expected_locations']) {
            return null;
        }

        $locations = [];
        foreach ($resolution['locations'] as $location) {
            if (! is_array($location)
                || ! is_string($location['label'] ?? null)
                || trim($location['label']) === ''
                || mb_strlen(trim($location['label'])) > 120
                || ! is_numeric($location['latitude'] ?? null)
                || ! is_numeric($location['longitude'] ?? null)) {
                return null;
            }
            $latitude = (float) $location['latitude'];
            $longitude = (float) $location['longitude'];
            $altitude = is_numeric($location['altitude_m'] ?? null) ? (float) $location['altitude_m'] : 0.0;
            if (! is_finite($latitude) || ! is_finite($longitude) || ! is_finite($altitude)
                || $latitude < -90 || $latitude > 90
                || $longitude < -180 || $longitude > 180
                || $altitude < -500 || $altitude > 10_000) {
                return null;
            }
            $locations[] = [
                'label' => trim($location['label']),
                'latitude' => round($latitude, 7),
                'longitude' => round($longitude, 7),
                'altitude_m' => round($altitude, 1),
            ];
        }

        return $locations;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  list<array<string, mixed>>  $selected
     * @return array{name: string, url: string|null, urls: list<string>, file_dates: list<string>, fetched_at: string|null, format: string, attribution: string, terms_url: string}
     */
    private function sourceMetadata(array $sources, array $selected): array
    {
        $selectedDates = array_fill_keys(array_map(
            static fn (array $record): string => (string) ($record['source_date'] ?? ''),
            $selected,
        ), true);
        $used = array_values(array_filter(
            $sources,
            static fn (array $source): bool => $selectedDates === []
                || isset($selectedDates[(string) ($source['source_date'] ?? '')]),
        ));
        usort($used, static fn (array $left, array $right): int => strcmp(
            (string) ($right['source_date'] ?? ''),
            (string) ($left['source_date'] ?? ''),
        ));
        $urls = array_values(array_unique(array_map(
            static fn (array $source): string => (string) $source['url'],
            $used,
        )));
        $dates = array_values(array_unique(array_map(
            static fn (array $source): string => (string) $source['source_date'],
            $used,
        )));
        $fetched = array_values(array_filter(array_map(
            static fn (array $source): ?string => is_string($source['fetched_at'] ?? null)
                ? $source['fetched_at']
                : null,
            $used,
        )));
        rsort($fetched);

        return [
            'name' => self::SOURCE_NAME,
            'url' => $urls[0] ?? null,
            'urls' => $urls,
            'file_dates' => $dates,
            'fetched_at' => $fetched[0] ?? null,
            'format' => 'RINEX 3 mixed navigation (gzip)',
            'attribution' => 'International GNSS Service (IGS), hosted by BKG',
            'terms_url' => 'https://igs.org/wp-content/uploads/2020/09/IGS-Data-and-Product-Disclaimer-and-Terms-of-Use-200805.pdf',
        ];
    }

    /**
     * @param  array{name: string, url: string|null, urls: list<string>, file_dates: list<string>, fetched_at: string|null, format: string, attribution: string, terms_url: string}|null  $source
     * @return array<string, mixed>
     */
    private function failureResult(
        CarbonImmutable $measuredAt,
        int $locationCount,
        float $mask,
        ?array $source = null,
    ): array {
        return [
            'complete' => false,
            'stale' => true,
            'measured_at' => $measuredAt->format('Y-m-d\TH:i:sP'),
            'location_count' => $locationCount,
            'elevation_mask_deg' => $mask,
            'counts' => [
                'visible' => null,
                'usable' => null,
                'visible_by_constellation' => ['gps' => null, 'galileo' => null],
                'usable_by_constellation' => ['gps' => null, 'galileo' => null],
            ],
            'pdop' => [
                'value' => null,
                'complete' => false,
                'geometry_sufficient' => null,
                'sample_count' => 0,
                'value_sample_count' => 0,
            ],
            'ephemeris' => [
                'satellite_count' => null,
                'gps' => null,
                'galileo' => null,
                'maximum_age_seconds' => null,
            ],
            'source' => $source ?? $this->sourceMetadata([], []),
            'provenance' => self::PROVENANCE,
        ];
    }

    private function sourceUrl(CarbonImmutable $date): string
    {
        $year = $date->format('Y');
        $day = sprintf('%03d', ((int) $date->format('z')) + 1);

        return self::BASE_URL."/{$year}/{$day}/BRDC00WRD_S_{$year}{$day}0000_01D_MN.rnx.gz";
    }

    private function isCachedSource(
        mixed $cached,
        string $sourceDate,
        string $url,
        int $maximumCacheAge,
    ): bool {
        if (! is_array($cached)
            || ($cached['source_date'] ?? null) !== $sourceDate
            || ($cached['url'] ?? null) !== $url
            || ($cached['rinex_version'] ?? null) !== '3.05'
            || ! is_string($cached['fetched_at'] ?? null)
            || ! is_array($cached['ephemerides'] ?? null)
            || $cached['ephemerides'] === []
            || count($cached['ephemerides']) > self::MAX_NAVIGATION_RECORDS) {
            return false;
        }
        try {
            $fetchedAt = CarbonImmutable::parse($cached['fetched_at'])->utc();
        } catch (Throwable) {
            return false;
        }
        if ($fetchedAt->isFuture()
            || $fetchedAt->diffInSeconds(CarbonImmutable::now('UTC')) > $maximumCacheAge) {
            return false;
        }
        foreach ($cached['ephemerides'] as $record) {
            if (! is_array($record) || ! $this->validEphemeris($record)) {
                return false;
            }
        }

        return true;
    }

    private function isCachedResult(
        mixed $cached,
        CarbonImmutable $measuredAt,
        int $locationCount,
        float $mask,
    ): bool {
        if (! is_array($cached)
            || ! is_bool($cached['complete'] ?? null)
            || ! is_bool($cached['stale'] ?? null)
            || ($cached['measured_at'] ?? null) !== $measuredAt->format('Y-m-d\TH:i:sP')
            || ($cached['location_count'] ?? null) !== $locationCount
            || ! is_numeric($cached['elevation_mask_deg'] ?? null)
            || abs((float) $cached['elevation_mask_deg'] - $mask) > 1e-9
            || ! is_array($cached['counts'] ?? null)
            || ! is_array($cached['pdop'] ?? null)
            || ! is_array($cached['ephemeris'] ?? null)
            || ! is_array($cached['source'] ?? null)
            || ($cached['provenance'] ?? null) !== self::PROVENANCE) {
            return false;
        }
        if ($cached['complete'] === false) {
            return $cached['stale'] === true
                && ($cached['counts']['visible'] ?? 'invalid') === null
                && ($cached['counts']['usable'] ?? 'invalid') === null
                && ($cached['pdop']['value'] ?? 'invalid') === null
                && ($cached['pdop']['complete'] ?? null) === false
                && array_key_exists('geometry_sufficient', $cached['pdop'])
                && $cached['pdop']['geometry_sufficient'] === null
                && ($cached['pdop']['sample_count'] ?? null) === 0
                && ($cached['pdop']['value_sample_count'] ?? null) === 0;
        }

        if ($cached['stale'] !== false
            || ! is_int($cached['counts']['visible'] ?? null)
            || ! is_int($cached['counts']['usable'] ?? null)
            || ($cached['pdop']['complete'] ?? null) !== true
            || ! is_bool($cached['pdop']['geometry_sufficient'] ?? null)
            || ($cached['pdop']['sample_count'] ?? null) !== $locationCount
            || ! is_int($cached['pdop']['value_sample_count'] ?? null)
            || $cached['pdop']['value_sample_count'] < 0
            || $cached['pdop']['value_sample_count'] > $locationCount) {
            return false;
        }

        return $cached['pdop']['geometry_sufficient'] === true
            ? is_numeric($cached['pdop']['value'] ?? null)
                && (float) $cached['pdop']['value'] > 0
                && $cached['pdop']['value_sample_count'] === $locationCount
            : ($cached['pdop']['value'] ?? 'invalid') === null
                && $cached['pdop']['value_sample_count'] < $locationCount;
    }

    /** @param list<array<string, mixed>> $records */
    private function constellationCounts(array $records): array
    {
        $counts = ['gps' => 0, 'galileo' => 0];
        foreach ($records as $record) {
            $constellation = $record['constellation'] ?? null;
            if (isset($counts[$constellation])) {
                $counts[$constellation]++;
            }
        }

        return $counts;
    }

    private function calculationBucket(CarbonImmutable $at): CarbonImmutable
    {
        $timestamp = intdiv($at->utc()->getTimestamp(), self::CALCULATION_BUCKET_SECONDS)
            * self::CALCULATION_BUCKET_SECONDS;

        return CarbonImmutable::createFromTimestampUTC($timestamp);
    }

    /**
     * @param  list<array{label: string, latitude: float, longitude: float, altitude_m: float}>  $locations
     */
    private function calculationCacheKey(
        array $locations,
        CarbonImmutable $measuredAt,
        float $mask,
    ): string {
        $locationJson = json_encode(
            $locations,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );

        return self::CACHE_NAMESPACE.':calculation:'
            .$measuredAt->format('YmdHi').':'.hash('sha256', $locationJson.':'.$mask);
    }

    private function gnssSystemTimestamp(CarbonImmutable $utc): float
    {
        // GPS time and Galileo system time currently lead UTC by 18 seconds.
        // Keeping this bounded and configurable makes a future leap-second
        // update an operational configuration change instead of a code patch.
        return (float) $utc->format('U.u')
            + $this->positiveConfig('gnss_utc_offset_seconds', 18, 0, 60);
    }

    private function elevationMask(): float
    {
        $configured = config('dis.wallboards.uav_forecast.gnss_elevation_mask_degrees', 10);
        if (! is_numeric($configured)) {
            return 10.0;
        }
        $value = (float) $configured;

        return is_finite($value) && $value >= 5 && $value <= 30 ? $value : 10.0;
    }

    private function positiveConfig(
        string $key,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        $configured = config('dis.wallboards.uav_forecast.'.$key, $default);
        if (! is_numeric($configured)) {
            return $default;
        }
        $value = (int) $configured;

        return $value >= $minimum && $value <= $maximum ? $value : $default;
    }

    private function normalizeAngle(float $angle): float
    {
        $normalized = fmod($angle, 2 * pi());

        return $normalized > pi() ? $normalized - (2 * pi()) : $normalized;
    }
}
