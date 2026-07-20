<?php

namespace App\Services;

use App\Models\Incident;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class IncidentLocationEnrichmentService
{
    public const SOURCE = 'pdok_brk_provinciegebied_wfs';

    public const COUNTRY_SOURCE = 'eurostat_gisco_country_id';

    public const MAX_RESPONSE_BYTES = 65_536;

    private const BBOX_EPSILON = 0.00001;

    /** @var array<string, string> */
    private const PROVINCES = [
        '20' => 'Groningen',
        '21' => 'Fryslân',
        '22' => 'Drenthe',
        '23' => 'Overijssel',
        '24' => 'Flevoland',
        '25' => 'Gelderland',
        '26' => 'Utrecht',
        '27' => 'Noord-Holland',
        '28' => 'Zuid-Holland',
        '29' => 'Zeeland',
        '30' => 'Noord-Brabant',
        '31' => 'Limburg',
    ];

    /** @var array<string, string> */
    private const COUNTRIES = [
        'NL' => 'Nederland',
        'BE' => 'België',
        'DE' => 'Duitsland',
    ];

    public function resolve(Incident $incident): bool
    {
        if (! (bool) config('dis.incident_location.enabled', true) || $incident->is_test) {
            return true;
        }

        $coordinates = $this->coordinateSnapshot($incident);
        $provincePending = $incident->province_resolved_at === null;
        $countryPending = $incident->country_resolved_at === null;

        if (! $coordinates['valid']) {
            $resolvedAt = now();
            $changes = [];
            if ($provincePending) {
                $changes += $this->provinceChanges(null, null, $resolvedAt);
            }
            if ($countryPending) {
                $changes += $this->countryChanges(null, null, $resolvedAt);
            }

            return $this->persistIfCoordinatesAreCurrent($incident, $coordinates, $changes);
        }

        if ($provincePending) {
            $province = $this->parseProvince($this->fetchProvince(
                (float) $coordinates['latitude'],
                (float) $coordinates['longitude'],
            ));
            $resolvedAt = now();
            $changes = $this->provinceChanges($province, self::SOURCE, $resolvedAt);
            if ($province !== null && $countryPending) {
                $changes += $this->countryChanges(
                    ['code' => 'NL', 'name' => self::COUNTRIES['NL']],
                    self::SOURCE,
                    $resolvedAt,
                );
                $countryPending = false;
            }
            if (! $this->persistIfCoordinatesAreCurrent($incident, $coordinates, $changes)) {
                return false;
            }
        } elseif ($countryPending && $this->storedProvinceProvesNetherlands($incident)) {
            if (! $this->persistIfCoordinatesAreCurrent(
                $incident,
                $coordinates,
                $this->countryChanges(
                    ['code' => 'NL', 'name' => self::COUNTRIES['NL']],
                    self::SOURCE,
                    now(),
                ),
            )) {
                return false;
            }
            $countryPending = false;
        }

        if ($countryPending) {
            $country = $this->parseCountry($this->fetchCountry(
                (float) $coordinates['latitude'],
                (float) $coordinates['longitude'],
            ));

            return $this->persistIfCoordinatesAreCurrent(
                $incident,
                $coordinates,
                $this->countryChanges($country, self::COUNTRY_SOURCE, now()),
            );
        }

        return true;
    }

    private function fetchProvince(float $latitude, float $longitude): string
    {
        $url = $this->configuredUrl(
            'wfs_url',
            'https://service.pdok.nl/kadaster/brk-bestuurlijke-gebieden/wfs/v1_0',
            'service.pdok.nl',
        );

        try {
            $response = Http::connectTimeout($this->timeout('connect_timeout_seconds', 2))
                ->timeout($this->timeout('timeout_seconds', 5))
                ->withOptions($this->boundedHttpOptions())
                ->withUserAgent('D.I.S Incident Location Enrichment')
                ->withHeader('Accept-Encoding', 'identity')
                ->accept('application/gml+xml; version=3.2, application/xml;q=0.9')
                ->get($url, [
                    'service' => 'WFS',
                    'version' => '2.0.0',
                    'request' => 'GetFeature',
                    'typeNames' => 'bestuurlijkegebieden:Provinciegebied',
                    'propertyName' => 'naam,code',
                    'count' => 2,
                    'srsName' => 'urn:ogc:def:crs:EPSG::4326',
                    // EPSG:4326 has latitude,longitude axis order in WFS 2.0.
                    'bbox' => sprintf(
                        '%.7F,%.7F,%.7F,%.7F,urn:ogc:def:crs:EPSG::4326',
                        $latitude - self::BBOX_EPSILON,
                        $longitude - self::BBOX_EPSILON,
                        $latitude + self::BBOX_EPSILON,
                        $longitude + self::BBOX_EPSILON,
                    ),
                ]);
        } catch (Throwable) {
            throw new RuntimeException('Incident province WFS transport failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('Incident province WFS returned HTTP %d.', $response->status()));
        }

        return $this->boundedBody($response->body(), 'Incident province WFS');
    }

    private function fetchCountry(float $latitude, float $longitude): string
    {
        $url = $this->configuredUrl(
            'country_url',
            'https://gisco-services.ec.europa.eu/id/country',
            'gisco-services.ec.europa.eu',
        );

        try {
            $response = Http::connectTimeout($this->timeout('connect_timeout_seconds', 2))
                ->timeout($this->timeout('timeout_seconds', 5))
                ->withOptions($this->boundedHttpOptions())
                ->withUserAgent('D.I.S Incident Location Enrichment')
                ->withHeader('Accept-Encoding', 'identity')
                ->acceptJson()
                ->get($url, [
                    'x' => $longitude,
                    'y' => $latitude,
                    'epsg' => 4326,
                    'year' => 2024,
                    'format' => 'json',
                    'geometry' => 'no',
                ]);
        } catch (Throwable) {
            throw new RuntimeException('Incident country lookup transport failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('Incident country lookup returned HTTP %d.', $response->status()));
        }

        return $this->boundedBody($response->body(), 'Incident country lookup');
    }

    /** @return array{code: string, name: string}|null */
    private function parseProvince(string $body): ?array
    {
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new RuntimeException('Incident province WFS returned unsafe XML.');
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML(
                $body,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (! $loaded || ! $document->documentElement instanceof DOMElement) {
            throw new RuntimeException('Incident province WFS returned invalid XML.');
        }

        $xpath = new DOMXPath($document);
        $exception = $xpath->query('//*[local-name()="ExceptionReport" or local-name()="Exception"]');
        if ($exception === false || $exception->length > 0) {
            throw new RuntimeException('Incident province WFS returned an exception document.');
        }

        if ($document->documentElement->localName !== 'FeatureCollection') {
            throw new RuntimeException('Incident province WFS returned an unexpected XML document.');
        }

        $features = $xpath->query('//*[local-name()="member"]/*[local-name()="Provinciegebied"]');
        if ($features === false) {
            throw new RuntimeException('Incident province WFS response could not be queried.');
        }
        if ($features->length !== 1) {
            return null;
        }

        $feature = $features->item(0);
        if (! $feature instanceof DOMElement) {
            throw new RuntimeException('Incident province WFS returned an invalid feature.');
        }

        $code = $this->childText($xpath, $feature, 'code');
        $name = $this->childText($xpath, $feature, 'naam');
        if ($code === null || $name === null || (self::PROVINCES[$code] ?? null) !== $name) {
            throw new RuntimeException('Incident province WFS returned a province outside the allowlist.');
        }

        return ['code' => $code, 'name' => $name];
    }

    /** @return array{code: string, name: string}|null */
    private function parseCountry(string $body): ?array
    {
        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Incident country lookup returned invalid JSON.');
        }

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new RuntimeException('Incident country lookup returned an invalid document.');
        }
        if ($payload === []) {
            return null;
        }
        if (count($payload) !== 1 || ! is_array($payload[0] ?? null) || ! is_array($payload[0]['attributes'] ?? null)) {
            throw new RuntimeException('Incident country lookup returned an ambiguous result.');
        }

        $attributes = $payload[0]['attributes'];
        $code = strtoupper(trim((string) ($attributes['id'] ?? '')));
        $objectId = strtoupper(trim((string) ($attributes['OBJECTID'] ?? $code)));
        if ($code === '' || $objectId !== $code) {
            throw new RuntimeException('Incident country lookup returned an invalid country identifier.');
        }
        if (! isset(self::COUNTRIES[$code])) {
            return null;
        }

        return ['code' => $code, 'name' => self::COUNTRIES[$code]];
    }

    private function childText(DOMXPath $xpath, DOMElement $feature, string $localName): ?string
    {
        $nodes = $xpath->query('./*[local-name()="'.$localName.'"]', $feature);
        if ($nodes === false || $nodes->length !== 1) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{latitude: string|null, longitude: string|null, valid: bool}
     */
    private function coordinateSnapshot(Incident $incident): array
    {
        $latitude = $this->canonicalCoordinate($incident->latitude);
        $longitude = $this->canonicalCoordinate($incident->longitude);

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'valid' => $this->validCoordinate($latitude, -90, 90)
                && $this->validCoordinate($longitude, -180, 180),
        ];
    }

    /**
     * @param  array{code: string, name: string}|null  $province
     * @return array<string, mixed>
     */
    private function provinceChanges(?array $province, ?string $source, mixed $resolvedAt): array
    {
        return [
            'province_code' => $province['code'] ?? null,
            'province_name' => $province['name'] ?? null,
            'province_source' => $source,
            'province_resolved_at' => $resolvedAt,
        ];
    }

    /**
     * @param  array{code: string, name: string}|null  $country
     * @return array<string, mixed>
     */
    private function countryChanges(?array $country, ?string $source, mixed $resolvedAt): array
    {
        return [
            'country_code' => $country['code'] ?? null,
            'country_name' => $country['name'] ?? null,
            'country_source' => $source,
            'country_resolved_at' => $resolvedAt,
        ];
    }

    /**
     * @param  array{latitude: string|null, longitude: string|null, valid: bool}  $coordinates
     * @param  array<string, mixed>  $changes
     */
    private function persistIfCoordinatesAreCurrent(Incident $incident, array $coordinates, array $changes): bool
    {
        if ($changes === []) {
            return true;
        }

        $query = DB::table('incidents')
            ->where('id', (string) $incident->getKey())
            ->whereNull('deleted_at');

        foreach (['latitude', 'longitude'] as $coordinate) {
            $value = $coordinates[$coordinate];
            $value === null
                ? $query->whereNull($coordinate)
                : $query->where($coordinate, $value);
        }
        if (array_key_exists('province_resolved_at', $changes)) {
            $query->whereNull('province_resolved_at');
        }
        if (array_key_exists('country_resolved_at', $changes)) {
            $query->whereNull('country_resolved_at');
        }

        return $query->update($changes) === 1;
    }

    private function storedProvinceProvesNetherlands(Incident $incident): bool
    {
        $code = (string) $incident->province_code;

        return isset(self::PROVINCES[$code]) && self::PROVINCES[$code] === $incident->province_name;
    }

    private function canonicalCoordinate(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) ? number_format($coordinate, 7, '.', '') : null;
    }

    private function validCoordinate(?string $value, float $minimum, float $maximum): bool
    {
        if ($value === null) {
            return false;
        }

        $coordinate = (float) $value;

        return $coordinate >= $minimum && $coordinate <= $maximum;
    }

    private function configuredUrl(string $key, string $default, string $allowedHost): string
    {
        $url = trim((string) config('dis.incident_location.'.$key, $default));
        $parts = $url === '' ? false : parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strcasecmp((string) ($parts['host'] ?? ''), $allowedHost) !== 0
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('A configured incident location enrichment URL is invalid.');
        }

        return $url;
    }

    /** @return array<string, mixed> */
    private function boundedHttpOptions(): array
    {
        return [
            'allow_redirects' => false,
            'decode_content' => false,
            'on_headers' => static function (ResponseInterface $response): void {
                $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                if ($encoding !== '' && $encoding !== 'identity') {
                    throw new RuntimeException('Incident location enrichment returned encoded content.');
                }
                $length = trim($response->getHeaderLine('Content-Length'));
                if ($length !== '' && ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Incident location enrichment response exceeded its size limit.');
                }
            },
            'progress' => static function (
                int $downloadTotal,
                int $downloadedBytes,
                int $uploadTotal,
                int $uploadedBytes,
            ): void {
                unset($downloadTotal, $uploadTotal, $uploadedBytes);
                if ($downloadedBytes > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Incident location enrichment response exceeded its size limit.');
                }
            },
        ];
    }

    private function boundedBody(string $body, string $provider): string
    {
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException($provider.' returned an empty or oversized response.');
        }

        return $body;
    }

    private function timeout(string $key, int $default): int
    {
        return max(1, min(10, (int) config('dis.incident_location.'.$key, $default)));
    }
}
