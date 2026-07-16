<?php

namespace App\Services\Routing;

use App\Contracts\RoutingProvider;
use App\DTO\Routing\RouteEstimate;
use App\DTO\Routing\RoutePoint;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;

final class OsrmRoutingProvider implements RoutingProvider
{
    private readonly string $baseUrl;

    private readonly string $profile;

    private readonly int $connectTimeoutSeconds;

    private readonly int $timeoutSeconds;

    private readonly int $batchSize;

    /** @var list<string> */
    private readonly array $allowedHosts;

    public function __construct(
        private readonly HttpFactory $http,
        string $baseUrl,
        string $profile = 'driving',
        int $connectTimeoutSeconds = 1,
        int $timeoutSeconds = 3,
        int $batchSize = 50,
        array $allowedHosts = ['127.0.0.1', 'localhost', '::1'],
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->profile = trim($profile);
        $this->connectTimeoutSeconds = max(1, min($connectTimeoutSeconds, 10));
        $this->timeoutSeconds = max($this->connectTimeoutSeconds, min($timeoutSeconds, 15));
        $this->batchSize = max(1, min($batchSize, 99));
        $this->allowedHosts = array_values(array_unique(array_filter(array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host, " \t\n\r\0\x0B[]")),
            $allowedHosts,
        ))));
    }

    public function isConfigured(): bool
    {
        if ($this->baseUrl === '' || preg_match('/^[A-Za-z0-9_-]+$/', $this->profile) !== 1) {
            return false;
        }

        $parts = parse_url($this->baseUrl);
        $host = is_array($parts)
            ? strtolower(trim((string) ($parts['host'] ?? ''), '[]'))
            : '';

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && $host !== ''
            && in_array($host, $this->allowedHosts, true)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    public function cacheNamespace(): string
    {
        return 'osrm:'.hash('sha256', $this->baseUrl.'|'.$this->profile);
    }

    /**
     * @param  array<string, RoutePoint>  $origins
     * @return array<string, RouteEstimate>
     */
    public function routesTo(array $origins, RoutePoint $destination): array
    {
        $this->assertOrigins($origins);

        $results = array_fill_keys(array_keys($origins), RouteEstimate::unknown());
        if ($origins === [] || ! $this->isConfigured()) {
            return $results;
        }

        foreach (array_chunk($origins, $this->batchSize, true) as $batch) {
            try {
                $results = array_replace($results, $this->requestBatch($batch, $destination));
            } catch (Throwable) {
                // Coordinates and provider responses must not be copied into logs.
                // Unknown rows are resolved by RoutingService's operational fallback.
                // Stop after the first provider failure so one alarm request has one
                // bounded timeout rather than a timeout for every remaining batch.
                break;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, RoutePoint>  $origins
     * @return array<string, RouteEstimate>
     */
    private function requestBatch(array $origins, RoutePoint $destination): array
    {
        $coordinates = array_map(
            static fn (RoutePoint $point): string => $point->osrmCoordinate(),
            array_values($origins),
        );
        $coordinates[] = $destination->osrmCoordinate();
        $destinationIndex = count($coordinates) - 1;
        $sourceIndexes = implode(';', range(0, $destinationIndex - 1));
        $url = sprintf(
            '%s/table/v1/%s/%s',
            $this->baseUrl,
            rawurlencode($this->profile),
            implode(';', $coordinates),
        );

        $response = $this->http
            ->acceptJson()
            ->connectTimeout($this->connectTimeoutSeconds)
            ->timeout($this->timeoutSeconds)
            // Never forward operational coordinates to a redirect target. The
            // configured host allowlist only applies to this original URL.
            ->withoutRedirecting()
            ->get($url, [
                'sources' => $sourceIndexes,
                'destinations' => (string) $destinationIndex,
                'annotations' => 'duration,distance',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('The configured routing provider did not return a successful response.');
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['code'] ?? null) !== 'Ok') {
            throw new RuntimeException('The configured routing provider returned an invalid result.');
        }

        $durations = $payload['durations'] ?? null;
        $distances = $payload['distances'] ?? null;
        if (! is_array($durations) || ! is_array($distances)) {
            throw new RuntimeException('The configured routing provider returned an incomplete matrix.');
        }

        $results = [];
        foreach (array_keys($origins) as $index => $key) {
            $duration = $this->matrixValue($durations, $index);
            $distance = $this->matrixValue($distances, $index);
            $results[$key] = $duration === null || $distance === null
                ? RouteEstimate::unknown()
                : RouteEstimate::navigation((int) ceil($duration), (int) ceil($distance));
        }

        return $results;
    }

    /**
     * @param  array<int, mixed>  $matrix
     */
    private function matrixValue(array $matrix, int $row): ?float
    {
        $value = is_array($matrix[$row] ?? null) ? ($matrix[$row][0] ?? null) : null;
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number >= 0 ? $number : null;
    }

    /**
     * @param  array<string, RoutePoint>  $origins
     */
    private function assertOrigins(array $origins): void
    {
        foreach ($origins as $key => $origin) {
            if (! is_string($key) || $key === '' || ! $origin instanceof RoutePoint) {
                throw new \InvalidArgumentException('Route origins must use non-empty string keys and RoutePoint values.');
            }
        }
    }
}
