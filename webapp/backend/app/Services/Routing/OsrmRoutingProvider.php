<?php

namespace App\Services\Routing;

use App\Contracts\RouteGeometryProvider;
use App\Contracts\RoutingProvider;
use App\DTO\Routing\RouteEstimate;
use App\DTO\Routing\RouteGeometry;
use App\DTO\Routing\RoutePoint;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

final class OsrmRoutingProvider implements RouteGeometryProvider, RoutingProvider
{
    private readonly string $baseUrl;

    private readonly string $profile;

    private readonly int $connectTimeoutSeconds;

    private readonly int $timeoutSeconds;

    private readonly int $batchSize;

    private readonly int $geometryMaxRoutes;

    private readonly int $geometryConcurrency;

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
        int $geometryMaxRoutes = 25,
        int $geometryConcurrency = 10,
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->profile = trim($profile);
        $this->connectTimeoutSeconds = max(1, min($connectTimeoutSeconds, 10));
        $this->timeoutSeconds = max($this->connectTimeoutSeconds, min($timeoutSeconds, 15));
        $this->batchSize = max(1, min($batchSize, 99));
        $this->geometryMaxRoutes = max(1, min($geometryMaxRoutes, 50));
        $this->geometryConcurrency = max(1, min($geometryConcurrency, $this->geometryMaxRoutes));
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
     * Route geometry is intentionally not cached: an operator's moving origin
     * must not leave a historical route payload in shared cache storage.
     *
     * @param  array<string, RoutePoint>  $origins
     * @return array<string, RouteGeometry>
     */
    public function routeGeometriesTo(array $origins, RoutePoint $destination): array
    {
        $this->assertOrigins($origins);
        if ($origins === [] || ! $this->isConfigured()) {
            return [];
        }

        // A live map poll may never fan out an unbounded number of routing
        // requests. Recipients beyond this hard, configuration-clamped limit
        // remain visible on the map but receive a null route overlay.
        $boundedOrigins = array_slice($origins, 0, $this->geometryMaxRoutes, true);

        try {
            $responses = $this->http->pool(function (Pool $pool) use ($boundedOrigins, $destination): void {
                foreach ($boundedOrigins as $key => $origin) {
                    $pool->as($key)
                        ->acceptJson()
                        ->connectTimeout($this->connectTimeoutSeconds)
                        ->timeout($this->timeoutSeconds)
                        ->withoutRedirecting()
                        ->get($this->routeUrl($origin, $destination), [
                            'alternatives' => 'false',
                            'steps' => 'false',
                            'overview' => 'simplified',
                            'geometries' => 'geojson',
                        ]);
                }
            }, $this->geometryConcurrency);
        } catch (Throwable) {
            return [];
        }

        $routes = [];
        foreach ($responses as $key => $response) {
            if (! is_string($key) || ! $response instanceof Response) {
                continue;
            }

            try {
                $route = $this->parseRouteGeometry($response);
                if ($route !== null) {
                    $routes[$key] = $route;
                }
            } catch (Throwable) {
                // Never log a provider payload because it contains current
                // operational coordinates. Other pilots' routes remain usable.
            }
        }

        return $routes;
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

    private function routeUrl(RoutePoint $origin, RoutePoint $destination): string
    {
        return sprintf(
            '%s/route/v1/%s/%s;%s',
            $this->baseUrl,
            rawurlencode($this->profile),
            $origin->osrmCoordinate(),
            $destination->osrmCoordinate(),
        );
    }

    private function parseRouteGeometry(Response $response): ?RouteGeometry
    {
        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        $route = is_array($payload) && ($payload['code'] ?? null) === 'Ok'
            && is_array($payload['routes'] ?? null)
            && count($payload['routes']) === 1
            ? ($payload['routes'][0] ?? null)
            : null;
        if (! is_array($route)
            || ! is_numeric($route['duration'] ?? null)
            || ! is_numeric($route['distance'] ?? null)
            || ! is_array($route['geometry'] ?? null)
            || ($route['geometry']['type'] ?? null) !== 'LineString'
            || ! is_array($route['geometry']['coordinates'] ?? null)) {
            return null;
        }

        $duration = (float) $route['duration'];
        $distance = (float) $route['distance'];
        if (! is_finite($duration) || $duration < 0 || ! is_finite($distance) || $distance < 0) {
            return null;
        }

        return RouteGeometry::navigation(
            (int) ceil($duration),
            (int) ceil($distance),
            $route['geometry']['coordinates'],
        );
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
