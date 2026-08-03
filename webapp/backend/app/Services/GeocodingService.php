<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

final class GeocodingService
{
    public const MAX_RESPONSE_BYTES = 65_536;

    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

    private const NOMINATIM_HOST = 'nominatim.openstreetmap.org';

    private const PDOK_URL = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free';

    private const PDOK_HOST = 'api.pdok.nl';

    /**
     * @return array{latitude: string, longitude: string}|null
     */
    public function coordinatesFor(?string $locationLabel): ?array
    {
        $query = trim((string) $locationLabel);
        if ($query === '' || ! (bool) config('dis.geocoding.enabled', true)) {
            return null;
        }

        foreach ($this->providerOrder() as $provider) {
            $coordinates = match ($provider) {
                'nominatim' => $this->nominatimCoordinatesFor($query),
                'pdok' => $this->pdokCoordinatesFor($query),
            };

            if ($coordinates !== null) {
                return $coordinates;
            }
        }

        return null;
    }

    /** @return list<'nominatim'|'pdok'> */
    private function providerOrder(): array
    {
        return match (strtolower(trim((string) config('dis.geocoding.provider', 'nominatim')))) {
            'nominatim' => ['nominatim', 'pdok'],
            'pdok' => ['pdok', 'nominatim'],
            default => [],
        };
    }

    /**
     * @return array{latitude: string, longitude: string}|null
     */
    private function nominatimCoordinatesFor(string $query): ?array
    {
        $url = $this->configuredProviderUrl('nominatim');
        if ($url === null) {
            return null;
        }

        $parameters = [
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => 1,
        ];

        $countryCodes = trim((string) config('dis.geocoding.country_codes', ''));
        if ($countryCodes !== '') {
            $parameters['countrycodes'] = $countryCodes;
        }

        $payload = $this->getJson($url, $parameters);
        if (! is_array($payload) || ! array_is_list($payload) || ! is_array($payload[0] ?? null)) {
            return null;
        }

        return $this->coordinatePair(
            $payload[0]['lat'] ?? null,
            $payload[0]['lon'] ?? null,
        );
    }

    /**
     * @return array{latitude: string, longitude: string}|null
     */
    private function pdokCoordinatesFor(string $query): ?array
    {
        $url = $this->configuredProviderUrl('pdok');
        if ($url === null) {
            return null;
        }

        $payload = $this->getJson($url, [
            // Quotes narrow PDOK's deliberately fuzzy ranking. The returned
            // label is still verified below before coordinates are accepted.
            'q' => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $query).'"',
            'rows' => 1,
        ]);
        $response = is_array($payload) ? ($payload['response'] ?? null) : null;
        $documents = is_array($response) ? ($response['docs'] ?? null) : null;
        $document = is_array($documents) && array_is_list($documents) ? ($documents[0] ?? null) : null;
        if (! is_array($document)
            || ! $this->pdokResultMatchesQuery($query, $document['weergavenaam'] ?? null)) {
            return null;
        }
        $point = is_array($document) ? trim((string) ($document['centroide_ll'] ?? '')) : '';
        if (preg_match('/^POINT\(([+-]?(?:\d+(?:\.\d+)?|\.\d+))\s+([+-]?(?:\d+(?:\.\d+)?|\.\d+))\)$/', $point, $matches) !== 1) {
            return null;
        }

        return $this->coordinatePair($matches[2], $matches[1]);
    }

    private function pdokResultMatchesQuery(string $query, mixed $displayLabel): bool
    {
        if (! is_string($displayLabel) || trim($displayLabel) === '') {
            return false;
        }

        $queryPostcodes = $this->dutchPostcodes($query);
        $resultPostcodes = $this->dutchPostcodes($displayLabel);
        if (count($queryPostcodes) > 1
            || count($resultPostcodes) > 1
            || ($queryPostcodes !== [] && $queryPostcodes !== $resultPostcodes)) {
            return false;
        }

        $normalizedQuery = $this->comparablePdokLabel($query);
        $normalizedResult = $this->comparablePdokLabel($displayLabel);

        // A false negative can be retried by Nominatim or the recovery job; a
        // false positive would persist an operationally wrong destination.
        return $normalizedQuery !== '' && $normalizedQuery === $normalizedResult;
    }

    private function comparablePdokLabel(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/\b[1-9]\d{3}\s*[a-z]{2}\b/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/(?:,\s*)?\b(?:nederland|netherlands|nl)\s*$/u', '', $normalized) ?? $normalized;
        $normalized = str_replace(',', ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? '';

        return trim($normalized);
    }

    /** @return list<string> */
    private function dutchPostcodes(string $value): array
    {
        if (preg_match_all('/\b([1-9]\d{3})\s*([a-z]{2})\b/ui', $value, $matches, PREG_SET_ORDER) < 1) {
            return [];
        }

        return array_values(array_map(
            static fn (array $match): string => strtolower($match[1].$match[2]),
            $matches,
        ));
    }

    /**
     * @param  array<string, int|string>  $parameters
     * @return array<mixed>|null
     */
    private function getJson(string $url, array $parameters): ?array
    {
        try {
            $response = Http::connectTimeout($this->timeout('connect_timeout_seconds', 2, 1, 5))
                ->timeout($this->timeout('timeout_seconds', 5, 2, 8))
                ->withOptions($this->boundedHttpOptions())
                ->withUserAgent($this->userAgent())
                ->withHeader('Accept-Encoding', 'identity')
                ->acceptJson()
                ->get($url, $parameters);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
                return null;
            }

            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

            return is_array($payload) ? $payload : null;
        } catch (Throwable) {
            // Provider exceptions can include the complete request URI. Do not
            // report them because the address query may contain sensitive data.
            return null;
        }
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
                    throw new RuntimeException('Geocoding provider returned an encoded response.');
                }
                $length = trim($response->getHeaderLine('Content-Length'));
                if ($length !== '' && ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Geocoding provider response exceeded its size limit.');
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
                    throw new RuntimeException('Geocoding provider response exceeded its size limit.');
                }
            },
        ];
    }

    private function configuredProviderUrl(string $provider): ?string
    {
        $configuration = match ($provider) {
            'nominatim' => ['nominatim_url', self::NOMINATIM_URL, self::NOMINATIM_HOST],
            'pdok' => ['pdok_url', self::PDOK_URL, self::PDOK_HOST],
            default => null,
        };
        if ($configuration === null) {
            return null;
        }
        [$key, $default, $allowedHost] = $configuration;
        $url = trim((string) config('dis.geocoding.'.$key, $default));
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
            return null;
        }

        return $url;
    }

    private function userAgent(): string
    {
        $configured = trim((string) config('dis.geocoding.user_agent', ''));
        if ($configured !== '') {
            return $configured;
        }

        return trim((string) config('app.url', 'D.I.S')).' D.I.S Geocoder';
    }

    /** @return array{latitude: string, longitude: string}|null */
    private function coordinatePair(mixed $latitude, mixed $longitude): ?array
    {
        $latitude = $this->coordinate($latitude, -90, 90);
        $longitude = $this->coordinate($longitude, -180, 180);
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => number_format($latitude, 7, '.', ''),
            'longitude' => number_format($longitude, 7, '.', ''),
        ];
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;
        if (! is_finite($coordinate) || $coordinate < $minimum || $coordinate > $maximum) {
            return null;
        }

        return $coordinate;
    }

    private function timeout(string $key, int $default, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) config('dis.geocoding.'.$key, $default)));
    }
}
