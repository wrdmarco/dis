<?php

namespace App\Services\Routing;

use App\Contracts\RoutingProvider;
use App\DTO\Routing\RouteEstimate;
use App\DTO\Routing\RoutePoint;
use App\DTO\Routing\RouteSource;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use InvalidArgumentException;
use Throwable;

final class RoutingService
{
    private const CACHE_VERSION = 'v1';

    public function __construct(
        private readonly RoutingProvider $provider,
        private readonly CacheRepository $cache,
        private readonly bool $enabled,
        private readonly int $cacheTtlSeconds,
        private readonly int $failureCacheTtlSeconds,
        private readonly float $fallbackSpeedKmh,
    ) {}

    /**
     * Returns route duration in seconds and distance in metres for every origin key.
     *
     * @param  array<string, RoutePoint>  $origins
     * @return array<string, RouteEstimate>
     */
    public function routesTo(array $origins, RoutePoint $destination): array
    {
        $this->assertOrigins($origins);
        if ($origins === []) {
            return [];
        }

        $navigationEnabled = $this->enabled && $this->provider->isConfigured();
        $resolved = [];
        $pending = [];
        foreach ($origins as $key => $origin) {
            $cached = $navigationEnabled ? $this->cachedEstimate($origin, $destination) : null;
            if ($cached === null) {
                $pending[$key] = $origin;
            } else {
                $resolved[$key] = $cached;
            }
        }

        if ($pending !== [] && $navigationEnabled) {
            try {
                $navigation = $this->provider->routesTo($pending, $destination);
            } catch (Throwable) {
                $navigation = [];
            }

            foreach ($pending as $key => $origin) {
                $estimate = $navigation[$key] ?? RouteEstimate::unknown();
                if (! $estimate instanceof RouteEstimate || $estimate->source !== RouteSource::Navigation) {
                    $estimate = RouteEstimate::unknown();
                }

                $this->cacheEstimate($origin, $destination, $estimate);
                $resolved[$key] = $estimate;
            }
        }

        foreach ($pending as $key => $origin) {
            $resolved[$key] ??= RouteEstimate::unknown();
        }

        $results = [];
        foreach ($origins as $key => $origin) {
            $estimate = $resolved[$key] ?? RouteEstimate::unknown();
            $results[$key] = $estimate->source === RouteSource::Unknown
                ? $this->fallback($origin, $destination)
                : $estimate;
        }

        return $results;
    }

    private function cachedEstimate(RoutePoint $origin, RoutePoint $destination): ?RouteEstimate
    {
        try {
            $payload = $this->cache->get($this->cacheKey($origin, $destination));
        } catch (Throwable) {
            return null;
        }
        if (! is_array($payload)) {
            return null;
        }

        $source = RouteSource::tryFrom(is_string($payload['source'] ?? null) ? $payload['source'] : '');
        if ($source === RouteSource::Unknown) {
            return RouteEstimate::unknown();
        }

        if ($source !== RouteSource::Navigation
            || ! is_int($payload['duration'] ?? null)
            || ! is_int($payload['distance'] ?? null)
            || $payload['duration'] < 0
            || $payload['distance'] < 0) {
            return null;
        }

        return RouteEstimate::navigation($payload['duration'], $payload['distance']);
    }

    private function cacheEstimate(RoutePoint $origin, RoutePoint $destination, RouteEstimate $estimate): void
    {
        $ttl = $estimate->source === RouteSource::Navigation
            ? max(0, $this->cacheTtlSeconds)
            : max(0, $this->failureCacheTtlSeconds);
        if ($ttl === 0) {
            return;
        }

        try {
            $this->cache->put(
                $this->cacheKey($origin, $destination),
                $estimate->toArray(),
                $ttl,
            );
        } catch (Throwable) {
            // Cache availability must never block navigation or its fallback.
        }
    }

    private function cacheKey(RoutePoint $origin, RoutePoint $destination): string
    {
        $routeIdentity = implode('|', [
            $this->provider->cacheNamespace(),
            $origin->fingerprint(),
            $destination->fingerprint(),
        ]);

        return 'routing:route:'.self::CACHE_VERSION.':'.hash('sha256', $routeIdentity);
    }

    private function fallback(RoutePoint $origin, RoutePoint $destination): RouteEstimate
    {
        if (! is_finite($this->fallbackSpeedKmh) || $this->fallbackSpeedKmh <= 0) {
            return RouteEstimate::unknown();
        }

        $earthRadiusMeters = 6_371_000;
        $latitudeDelta = deg2rad($destination->latitude - $origin->latitude);
        $longitudeDelta = deg2rad($destination->longitude - $origin->longitude);
        $originLatitude = deg2rad($origin->latitude);
        $destinationLatitude = deg2rad($destination->latitude);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($originLatitude) * cos($destinationLatitude) * sin($longitudeDelta / 2) ** 2;
        $distance = 2 * $earthRadiusMeters * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
        $duration = $distance / ($this->fallbackSpeedKmh / 3.6);

        if (! is_finite($distance) || ! is_finite($duration)) {
            return RouteEstimate::unknown();
        }

        return RouteEstimate::fallback((int) ceil($duration), (int) ceil($distance));
    }

    /**
     * @param  array<string, RoutePoint>  $origins
     */
    private function assertOrigins(array $origins): void
    {
        foreach ($origins as $key => $origin) {
            if (! is_string($key) || $key === '' || ! $origin instanceof RoutePoint) {
                throw new InvalidArgumentException('Route origins must use non-empty string keys and RoutePoint values.');
            }
        }
    }
}
