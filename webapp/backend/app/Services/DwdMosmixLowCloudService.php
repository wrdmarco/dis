<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads the official DWD MOSMIX_L Nl and N05 parameters without creating
 * snapshots or temporary files. Both the KMZ archive and KML document stay
 * in memory; only the small, validated reading is shared through Laravel's
 * application cache.
 */
final class DwdMosmixLowCloudService
{
    private const BASE_URL = 'https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations';

    private const CACHE_NAMESPACE = 'wallboard:uav-forecast:dwd-mosmix-low-cloud:v1';

    private const MAX_STATIONS = 12;

    private const POOL_CONCURRENCY = 4;

    private const REQUEST_ATTEMPTS = 2;

    private const MAX_RESPONSE_BYTES = 262_144;

    private const MAX_KML_BYTES = 1_048_576;

    private const MAX_FORECAST_STEPS = 300;

    /**
     * @param  list<string>  $stationIds
     * @return array<string, array{
     *     station_id: string,
     *     model_run_at: string,
     *     valid_at: string,
     *     cloud_cover_low_pct: float,
     *     cloud_cover_below_500ft_pct: float
     * }>
     */
    public function forStations(array $stationIds, CarbonImmutable $validAt): array
    {
        $stations = $this->validatedStationIds($stationIds);
        if ($stations === null || $stations === []) {
            return [];
        }

        $validAt = $validAt->utc();
        $results = [];
        $locks = [];
        $toFetch = [];

        try {
            foreach ($stations as $stationId) {
                $cacheKey = $this->cacheKey($stationId, $validAt);
                $cached = Cache::get($cacheKey.':fresh');
                if ($this->isCurrentReading($cached, $stationId, $validAt)) {
                    $results[$stationId] = $cached;

                    continue;
                }

                $lock = Cache::lock(
                    $cacheKey.':lock',
                    $this->lockSeconds(count($stations)),
                );
                if (! $lock->get()) {
                    $lastGood = Cache::get($cacheKey.':last-good');
                    if ($this->isCurrentReading($lastGood, $stationId, $validAt)) {
                        $results[$stationId] = $lastGood;
                    }

                    continue;
                }
                $locks[$stationId] = $lock;

                $cached = Cache::get($cacheKey.':fresh');
                if ($this->isCurrentReading($cached, $stationId, $validAt)) {
                    $results[$stationId] = $cached;

                    continue;
                }

                $toFetch[] = $stationId;
            }

            if ($toFetch !== []) {
                $responses = Http::pool(function (Pool $pool) use ($toFetch): void {
                    foreach ($toFetch as $stationId) {
                        $this->configureRequest($pool->as('station-'.$stationId))
                            ->get($this->stationUrl($stationId));
                    }
                }, min(self::POOL_CONCURRENCY, count($toFetch)));

                foreach ($toFetch as $stationId) {
                    $response = $responses['station-'.$stationId] ?? null;
                    if (! $response instanceof Response) {
                        $this->useLastGood($results, $stationId, $validAt);

                        continue;
                    }

                    try {
                        $reading = $this->parseResponse($response, $stationId, $validAt);
                        $results[$stationId] = $reading;
                        $cacheKey = $this->cacheKey($stationId, $validAt);
                        Cache::put(
                            $cacheKey.':fresh',
                            $reading,
                            $this->positiveConfig('dwd_mosmix_cache_seconds', 900, 60, 3600),
                        );
                        Cache::put(
                            $cacheKey.':last-good',
                            $reading,
                            $this->positiveConfig(
                                'dwd_mosmix_last_good_cache_seconds',
                                21600,
                                900,
                                86400,
                            ),
                        );
                    } catch (Throwable) {
                        $this->useLastGood($results, $stationId, $validAt);
                    }
                }
            }
        } catch (Throwable) {
            // Missing cache or network infrastructure must not turn an
            // unverified cloud layer into a seemingly safe reading.
        } finally {
            foreach ($locks as $lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // The bounded cache entry is sufficient if a lock expires.
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<string, array<string, mixed>>  $results
     */
    private function useLastGood(
        array &$results,
        string $stationId,
        CarbonImmutable $validAt,
    ): void {
        try {
            $lastGood = Cache::get($this->cacheKey($stationId, $validAt).':last-good');
            if ($this->isCurrentReading($lastGood, $stationId, $validAt)) {
                $results[$stationId] = $lastGood;
            }
        } catch (Throwable) {
            // Fail closed for this station only. The Bright Sky weather
            // parameters remain independently usable.
        }
    }

    /**
     * @return array{
     *     station_id: string,
     *     model_run_at: string,
     *     valid_at: string,
     *     cloud_cover_low_pct: float,
     *     cloud_cover_below_500ft_pct: float
     * }
     */
    private function parseResponse(
        Response $response,
        string $stationId,
        CarbonImmutable $validAt,
    ): array {
        if ($response->status() !== 200
            || $response->redirect()
            || trim((string) $response->header('Location')) !== '') {
            throw new \RuntimeException('DWD MOSMIX returned an unexpected HTTP status.');
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $body = $response->body();
        if ($contentType !== 'application/vnd.google-earth.kmz'
            || strlen($body) < 64
            || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new \RuntimeException('DWD MOSMIX returned an invalid KMZ response.');
        }

        return $this->parseKml(
            $this->extractSingleKml($body, $stationId),
            $stationId,
            $validAt,
        );
    }

    private function extractSingleKml(string $kmz, string $stationId): string
    {
        $length = strlen($kmz);
        $tailOffset = max(0, $length - 65_557);
        $relativeEocd = strrpos(substr($kmz, $tailOffset), "PK\x05\x06");
        if ($relativeEocd === false) {
            throw new \UnexpectedValueException('DWD KMZ end record is missing.');
        }
        $eocd = $tailOffset + $relativeEocd;
        if ($eocd + 22 > $length) {
            throw new \UnexpectedValueException('DWD KMZ end record is truncated.');
        }

        $disk = $this->uint16($kmz, $eocd + 4);
        $centralDisk = $this->uint16($kmz, $eocd + 6);
        $diskEntries = $this->uint16($kmz, $eocd + 8);
        $totalEntries = $this->uint16($kmz, $eocd + 10);
        $centralSize = $this->uint32($kmz, $eocd + 12);
        $centralOffset = $this->uint32($kmz, $eocd + 16);
        $commentLength = $this->uint16($kmz, $eocd + 20);
        if ($disk !== 0
            || $centralDisk !== 0
            || $diskEntries !== 1
            || $totalEntries !== 1
            || $eocd + 22 + $commentLength !== $length
            || $centralOffset + $centralSize !== $eocd
            || $centralSize < 46
            || substr($kmz, $centralOffset, 4) !== "PK\x01\x02") {
            throw new \UnexpectedValueException('DWD KMZ directory is invalid.');
        }

        $centralFlags = $this->uint16($kmz, $centralOffset + 8);
        $method = $this->uint16($kmz, $centralOffset + 10);
        $crc = $this->uint32($kmz, $centralOffset + 16);
        $compressedSize = $this->uint32($kmz, $centralOffset + 20);
        $uncompressedSize = $this->uint32($kmz, $centralOffset + 24);
        $nameLength = $this->uint16($kmz, $centralOffset + 28);
        $extraLength = $this->uint16($kmz, $centralOffset + 30);
        $entryCommentLength = $this->uint16($kmz, $centralOffset + 32);
        $entryDisk = $this->uint16($kmz, $centralOffset + 34);
        $localOffset = $this->uint32($kmz, $centralOffset + 42);
        $centralEntryLength = 46 + $nameLength + $extraLength + $entryCommentLength;
        $entryName = substr($kmz, $centralOffset + 46, $nameLength);
        $expectedNamePattern = '/\AMOSMIX_L_\d{10}_'.preg_quote($stationId, '/').'\.kml\z/D';
        if (($centralFlags & 0x0001) !== 0
            || ! in_array($method, [0, 8], true)
            || $entryDisk !== 0
            || $centralEntryLength !== $centralSize
            || $compressedSize < 1
            || $compressedSize > self::MAX_RESPONSE_BYTES
            || $uncompressedSize < 1
            || $uncompressedSize > self::MAX_KML_BYTES
            || preg_match($expectedNamePattern, $entryName) !== 1
            || $localOffset + 30 > $centralOffset
            || substr($kmz, $localOffset, 4) !== "PK\x03\x04") {
            throw new \UnexpectedValueException('DWD KMZ entry is invalid.');
        }

        $localFlags = $this->uint16($kmz, $localOffset + 6);
        $localMethod = $this->uint16($kmz, $localOffset + 8);
        $localNameLength = $this->uint16($kmz, $localOffset + 26);
        $localExtraLength = $this->uint16($kmz, $localOffset + 28);
        $localName = substr($kmz, $localOffset + 30, $localNameLength);
        $compressedOffset = $localOffset + 30 + $localNameLength + $localExtraLength;
        if ($localFlags !== $centralFlags
            || $localMethod !== $method
            || $localName !== $entryName
            || $compressedOffset + $compressedSize > $centralOffset) {
            throw new \UnexpectedValueException('DWD KMZ local entry is invalid.');
        }

        $compressed = substr($kmz, $compressedOffset, $compressedSize);
        $kml = $method === 8
            ? @gzinflate($compressed, self::MAX_KML_BYTES)
            : $compressed;
        if (! is_string($kml)
            || strlen($kml) !== $uncompressedSize
            || strtolower(sprintf('%08x', $crc)) !== hash('crc32b', $kml)) {
            throw new \UnexpectedValueException('DWD KMZ payload integrity check failed.');
        }

        return $kml;
    }

    /**
     * @return array{
     *     station_id: string,
     *     model_run_at: string,
     *     valid_at: string,
     *     cloud_cover_low_pct: float,
     *     cloud_cover_below_500ft_pct: float
     * }
     */
    private function parseKml(
        string $kml,
        string $stationId,
        CarbonImmutable $validAt,
    ): array {
        if (stripos($kml, '<!DOCTYPE') !== false
            || stripos($kml, '<!ENTITY') !== false) {
            throw new \UnexpectedValueException('DWD KML contains an unsafe document declaration.');
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML(
                $kml,
                LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
        if (! $loaded) {
            throw new \UnexpectedValueException('DWD KML is malformed.');
        }

        $xpath = new DOMXPath($document);
        if ($this->singleText($xpath, '//*[local-name()="Issuer"]') !== 'Deutscher Wetterdienst'
            || $this->singleText($xpath, '//*[local-name()="ProductID"]') !== 'MOSMIX') {
            throw new \UnexpectedValueException('DWD KML product identity is invalid.');
        }

        $modelRunAt = $this->timestamp(
            $this->singleText($xpath, '//*[local-name()="IssueTime"]'),
        );
        $now = CarbonImmutable::now('UTC');
        if ($modelRunAt === null
            || $modelRunAt->greaterThan($now->addHour())
            || $modelRunAt->lessThan($now->subSeconds(
                $this->positiveConfig('dwd_mosmix_model_stale_seconds', 43200, 21600, 86400),
            ))
            || ! $modelRunAt->lessThan($validAt)) {
            throw new \UnexpectedValueException('DWD MOSMIX model run is invalid or stale.');
        }

        $placemarks = $xpath->query('//*[local-name()="Placemark"]');
        if ($placemarks === false || $placemarks->length !== 1) {
            throw new \UnexpectedValueException('DWD KML station structure is invalid.');
        }
        $placemark = $placemarks->item(0);
        if (! $placemark instanceof DOMElement
            || $this->singleText($xpath, './*[local-name()="name"]', $placemark) !== $stationId) {
            throw new \UnexpectedValueException('DWD KML station identity does not match.');
        }

        $timeNodes = $xpath->query('//*[local-name()="ForecastTimeSteps"]/*[local-name()="TimeStep"]');
        if ($timeNodes === false
            || $timeNodes->length < 1
            || $timeNodes->length > self::MAX_FORECAST_STEPS) {
            throw new \UnexpectedValueException('DWD KML forecast time steps are invalid.');
        }

        $times = [];
        $previous = null;
        foreach ($timeNodes as $timeNode) {
            $time = $this->timestamp(trim($timeNode->textContent));
            if ($time === null || ($previous !== null && ! $time->greaterThan($previous))) {
                throw new \UnexpectedValueException('DWD KML forecast times are not ordered.');
            }
            $times[] = $time;
            $previous = $time;
        }

        $validIndex = null;
        foreach ($times as $index => $time) {
            if ($time->equalTo($validAt)) {
                $validIndex = $index;
                break;
            }
        }
        if ($validIndex === null) {
            throw new \UnexpectedValueException('DWD KML does not contain the requested hour.');
        }

        $lowValues = $this->forecastValues($xpath, $placemark, 'Nl', count($times));
        $below500Values = $this->forecastValues($xpath, $placemark, 'N05', count($times));
        $low = $lowValues[$validIndex] ?? null;
        $below500 = $below500Values[$validIndex] ?? null;
        if ($low === null || $below500 === null) {
            throw new \UnexpectedValueException('DWD low-cloud value is unavailable for the requested hour.');
        }

        return [
            'station_id' => $stationId,
            'model_run_at' => $modelRunAt->toIso8601String(),
            'valid_at' => $validAt->toIso8601String(),
            'cloud_cover_low_pct' => $low,
            'cloud_cover_below_500ft_pct' => $below500,
        ];
    }

    /**
     * @return list<float|null>
     */
    private function forecastValues(
        DOMXPath $xpath,
        DOMElement $placemark,
        string $elementName,
        int $expectedCount,
    ): array {
        $query = './/*[local-name()="Forecast" and @*[local-name()="elementName"]="'
            .$elementName.'"]/*[local-name()="value"]';
        $nodes = $xpath->query($query, $placemark);
        if ($nodes === false || $nodes->length !== 1) {
            throw new \UnexpectedValueException("DWD KML parameter {$elementName} is missing.");
        }

        $tokens = preg_split('/\s+/', trim($nodes->item(0)?->textContent ?? ''));
        if (! is_array($tokens) || count($tokens) !== $expectedCount) {
            throw new \UnexpectedValueException("DWD KML parameter {$elementName} has invalid length.");
        }

        $values = [];
        foreach ($tokens as $token) {
            if ($token === '-') {
                $values[] = null;

                continue;
            }
            if (preg_match('/\A(?:\d{1,3})(?:\.\d{1,2})?\z/D', $token) !== 1) {
                throw new \UnexpectedValueException("DWD KML parameter {$elementName} is invalid.");
            }
            $value = (float) $token;
            if (! is_finite($value) || $value < 0 || $value > 100) {
                throw new \UnexpectedValueException("DWD KML parameter {$elementName} is out of range.");
            }
            $values[] = $value;
        }

        return $values;
    }

    private function singleText(
        DOMXPath $xpath,
        string $query,
        ?DOMElement $context = null,
    ): string {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length !== 1) {
            throw new \UnexpectedValueException('DWD KML required field is not unique.');
        }

        return trim($nodes->item(0)?->textContent ?? '');
    }

    /** @return list<string>|null */
    private function validatedStationIds(array $stationIds): ?array
    {
        if (! array_is_list($stationIds)
            || count($stationIds) < 1
            || count($stationIds) > self::MAX_STATIONS) {
            return null;
        }

        $validated = [];
        foreach ($stationIds as $stationId) {
            if (! is_string($stationId)
                || preg_match('/\A[A-Z0-9]{5}\z/D', $stationId) !== 1) {
                return null;
            }
            $validated[$stationId] = true;
        }

        return array_keys($validated);
    }

    private function isCurrentReading(
        mixed $reading,
        string $stationId,
        CarbonImmutable $validAt,
    ): bool {
        if (! is_array($reading)
            || ($reading['station_id'] ?? null) !== $stationId
            || ($reading['valid_at'] ?? null) !== $validAt->toIso8601String()
            || ! is_numeric($reading['cloud_cover_low_pct'] ?? null)
            || ! is_numeric($reading['cloud_cover_below_500ft_pct'] ?? null)) {
            return false;
        }

        $low = (float) $reading['cloud_cover_low_pct'];
        $below500 = (float) $reading['cloud_cover_below_500ft_pct'];
        $modelRunAt = $this->timestamp($reading['model_run_at'] ?? null);

        return is_finite($low)
            && is_finite($below500)
            && $low >= 0
            && $low <= 100
            && $below500 >= 0
            && $below500 <= 100
            && $modelRunAt !== null
            && $modelRunAt->lessThan($validAt)
            && ! $modelRunAt->lessThan(CarbonImmutable::now('UTC')->subSeconds(
                $this->positiveConfig('dwd_mosmix_model_stale_seconds', 43200, 21600, 86400),
            ));
    }

    private function configureRequest(PendingRequest $request): PendingRequest
    {
        return $request
            ->accept('application/vnd.google-earth.kmz')
            ->withHeaders([
                'Accept-Encoding' => 'identity',
                'User-Agent' => 'DIS-UAV-Weather/1.0 (+https://nationaaldroneteam.nl)',
            ])
            ->connectTimeout(
                $this->positiveConfig('dwd_mosmix_connect_timeout_seconds', 2, 1, 3),
            )
            ->timeout(
                $this->positiveConfig('dwd_mosmix_timeout_seconds', 5, 2, 8),
            )
            ->withoutRedirecting()
            ->withOptions($this->boundedOptions())
            ->retry(
                self::REQUEST_ATTEMPTS,
                fn (int $attempt): int => $this->positiveConfig(
                    'dwd_mosmix_retry_delay_ms',
                    250,
                    50,
                    1000,
                ) * $attempt,
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
        $options = [
            'allow_redirects' => false,
            'decode_content' => false,
            'http_errors' => false,
            'verify' => true,
            'on_headers' => static function ($response): void {
                $length = trim((string) $response->getHeaderLine('Content-Length'));
                if ($length !== ''
                    && (! ctype_digit($length) || (int) $length > self::MAX_RESPONSE_BYTES)) {
                    throw new \RuntimeException('DWD KMZ response length is invalid.');
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
                    throw new \RuntimeException('DWD KMZ response exceeded its size limit.');
                }
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options['curl'] = [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        }

        return $options;
    }

    private function stationUrl(string $stationId): string
    {
        return self::BASE_URL.'/'.$stationId.'/kml/MOSMIX_L_LATEST_'.$stationId.'.kmz';
    }

    private function cacheKey(string $stationId, CarbonImmutable $validAt): string
    {
        return self::CACHE_NAMESPACE.':'.$stationId.':'.$validAt->format('YmdH');
    }

    private function lockSeconds(int $stationCount): int
    {
        $timeout = $this->positiveConfig('dwd_mosmix_timeout_seconds', 5, 2, 8);
        $waves = (int) ceil($stationCount / self::POOL_CONCURRENCY);
        $minimum = ($waves * self::REQUEST_ATTEMPTS * $timeout) + 10;
        $configured = $this->positiveConfig('dwd_mosmix_lock_seconds', 20, 10, 90);

        return min(90, max($minimum, $configured));
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

    private function uint16(string $bytes, int $offset): int
    {
        if ($offset < 0 || $offset + 2 > strlen($bytes)) {
            throw new \UnexpectedValueException('DWD KMZ integer is truncated.');
        }
        $value = unpack('vvalue', substr($bytes, $offset, 2));

        return (int) ($value['value'] ?? -1);
    }

    private function uint32(string $bytes, int $offset): int
    {
        if ($offset < 0 || $offset + 4 > strlen($bytes)) {
            throw new \UnexpectedValueException('DWD KMZ integer is truncated.');
        }
        $value = unpack('Vvalue', substr($bytes, $offset, 4));

        return (int) ($value['value'] ?? -1);
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
