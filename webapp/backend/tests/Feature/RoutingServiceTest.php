<?php

namespace Tests\Feature;

use App\DTO\Routing\RoutePoint;
use App\DTO\Routing\RouteSource;
use App\Services\Routing\OsrmRoutingProvider;
use App\Services\Routing\RoutingService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;

final class RoutingServiceTest extends TestCase
{
    public function test_osrm_provider_batches_origins_and_parses_duration_and_distance(): void
    {
        $requestNumber = 0;
        $http = new HttpFactory;
        $http->fake(function (Request $request) use (&$requestNumber) {
            $requestNumber++;

            return $requestNumber === 1
                ? HttpFactory::response([
                    'code' => 'Ok',
                    'durations' => [[120.1], [300]],
                    'distances' => [[1000.1], [4000]],
                ])
                : HttpFactory::response([
                    'code' => 'Ok',
                    'durations' => [[40.2]],
                    'distances' => [[500.4]],
                ]);
        });

        $provider = $this->provider($http, batchSize: 2);
        $routes = $provider->routesTo([
            'pilot-a' => new RoutePoint(52.0907, 5.1214),
            'pilot-b' => new RoutePoint(52.3702, 4.8952),
            'pilot-c' => new RoutePoint(51.9244, 4.4777),
        ], new RoutePoint(52.1561, 5.3878));

        $http->assertSentCount(2);
        $http->assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/table/v1/driving/5.121400,52.090700;4.895200,52.370200;5.387800,52.156100')
                && str_contains($url, 'sources=0;1')
                && str_contains($url, 'destinations=2')
                && str_contains($url, 'annotations=duration,distance');
        });

        $this->assertSame(121, $routes['pilot-a']->duration);
        $this->assertSame(1001, $routes['pilot-a']->distance);
        $this->assertSame(RouteSource::Navigation, $routes['pilot-a']->source);
        $this->assertSame(300, $routes['pilot-b']->duration);
        $this->assertSame(41, $routes['pilot-c']->duration);
        $this->assertSame(501, $routes['pilot-c']->distance);
    }

    public function test_osrm_provider_marks_unroutable_or_malformed_rows_unknown(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => HttpFactory::response([
                'code' => 'Ok',
                'durations' => [[null], ['invalid'], [10]],
                'distances' => [[100], [200], [-1]],
            ]),
        ]);

        $routes = $this->provider($http)->routesTo([
            'no-route' => new RoutePoint(52.0, 5.0),
            'bad-duration' => new RoutePoint(52.1, 5.1),
            'bad-distance' => new RoutePoint(52.2, 5.2),
        ], new RoutePoint(52.3, 5.3));

        foreach ($routes as $route) {
            $this->assertSame(RouteSource::Unknown, $route->source);
            $this->assertNull($route->duration);
            $this->assertNull($route->distance);
        }
    }

    public function test_osrm_provider_stops_after_first_failed_batch(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => HttpFactory::response([], 503)]);

        $routes = $this->provider($http, batchSize: 2)->routesTo([
            'pilot-a' => new RoutePoint(52.0, 5.0),
            'pilot-b' => new RoutePoint(52.1, 5.1),
            'pilot-c' => new RoutePoint(52.2, 5.2),
            'pilot-d' => new RoutePoint(52.3, 5.3),
            'pilot-e' => new RoutePoint(52.4, 5.4),
        ], new RoutePoint(52.5, 5.5));

        $http->assertSentCount(1);
        foreach ($routes as $route) {
            $this->assertSame(RouteSource::Unknown, $route->source);
        }
    }

    public function test_osrm_provider_never_follows_a_redirect_with_operational_coordinates(): void
    {
        $history = [];
        $mock = new MockHandler([
            new PsrResponse(302, ['Location' => 'http://metadata.invalid/collect']),
            new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode([
                'code' => 'Ok',
                'durations' => [[60]],
                'distances' => [[1000]],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new HttpFactory;
        $http->globalOptions(['handler' => $stack]);

        $route = $this->provider($http)->routesTo(
            ['pilot-a' => new RoutePoint(52.0, 5.0)],
            new RoutePoint(52.1, 5.1),
        )['pilot-a'];

        $this->assertCount(1, $history);
        $this->assertSame('osrm.internal.test', $history[0]['request']->getUri()->getHost());
        $this->assertSame(RouteSource::Unknown, $route->source);
    }

    public function test_osrm_provider_rejects_a_base_url_outside_the_exact_host_allowlist(): void
    {
        $http = new HttpFactory;
        $http->fake();
        $provider = new OsrmRoutingProvider(
            http: $http,
            baseUrl: 'https://router.example.test',
            allowedHosts: ['127.0.0.1', 'localhost'],
        );

        $this->assertFalse($provider->isConfigured());
        $provider->routesTo(
            ['pilot-a' => new RoutePoint(52.0, 5.0)],
            new RoutePoint(52.1, 5.1),
        );
        $http->assertNothingSent();
    }

    public function test_routing_service_caches_navigation_results_under_hashed_keys(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => HttpFactory::response([
                'code' => 'Ok',
                'durations' => [[600]],
                'distances' => [[12_000]],
            ]),
        ]);
        $store = new ArrayStore;
        $service = $this->service($http, new Repository($store));
        $origin = new RoutePoint(52.0907, 5.1214);
        $destination = new RoutePoint(52.1561, 5.3878);

        $first = $service->routesTo(['pilot-a' => $origin], $destination);
        $second = $service->routesTo(['pilot-a' => $origin], $destination);

        $http->assertSentCount(1);
        $this->assertSame(RouteSource::Navigation, $first['pilot-a']->source);
        $this->assertSame($first['pilot-a']->toArray(), $second['pilot-a']->toArray());

        $keys = array_keys($store->all());
        $this->assertCount(1, $keys);
        $this->assertMatchesRegularExpression('/^routing:route:v1:[a-f0-9]{64}$/', $keys[0]);
        $this->assertStringNotContainsString($origin->fingerprint(), $keys[0]);
        $this->assertStringNotContainsString($destination->fingerprint(), $keys[0]);
        $this->assertStringNotContainsString('osrm.internal.test', $keys[0]);
    }

    public function test_routing_service_falls_back_and_temporarily_caches_provider_failure(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => HttpFactory::response([], 503)]);
        $store = new ArrayStore;
        $service = $this->service($http, new Repository($store));
        $origins = [
            'pilot-a' => new RoutePoint(52.0907, 5.1214),
            'pilot-b' => new RoutePoint(52.3702, 4.8952),
        ];
        $destination = new RoutePoint(52.1561, 5.3878);

        $first = $service->routesTo($origins, $destination);
        $second = $service->routesTo($origins, $destination);

        $http->assertSentCount(1);
        foreach ($first as $key => $route) {
            $this->assertSame(RouteSource::Fallback, $route->source);
            $this->assertGreaterThan(0, $route->duration);
            $this->assertGreaterThan(0, $route->distance);
            $this->assertSame($route->toArray(), $second[$key]->toArray());
        }
    }

    public function test_disabled_routing_uses_fallback_without_contacting_provider(): void
    {
        $http = new HttpFactory;
        $http->fake();
        $service = $this->service($http, new Repository(new ArrayStore), enabled: false);

        $route = $service->routesTo(
            ['pilot-a' => new RoutePoint(52.0907, 5.1214)],
            new RoutePoint(52.1561, 5.3878),
        )['pilot-a'];

        $http->assertNothingSent();
        $this->assertSame(RouteSource::Fallback, $route->source);
    }

    public function test_invalid_fallback_speed_produces_an_explicit_unknown_result(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => HttpFactory::response([], 503)]);
        $service = $this->service($http, new Repository(new ArrayStore), fallbackSpeedKmh: 0);

        $route = $service->routesTo(
            ['pilot-a' => new RoutePoint(52.0907, 5.1214)],
            new RoutePoint(52.1561, 5.3878),
        )['pilot-a'];

        $this->assertSame(RouteSource::Unknown, $route->source);
        $this->assertSame([
            'duration' => null,
            'distance' => null,
            'source' => 'unknown',
        ], $route->toArray());
    }

    private function provider(HttpFactory $http, int $batchSize = 50): OsrmRoutingProvider
    {
        return new OsrmRoutingProvider(
            http: $http,
            baseUrl: 'http://osrm.internal.test:5000',
            profile: 'driving',
            connectTimeoutSeconds: 1,
            timeoutSeconds: 3,
            batchSize: $batchSize,
            allowedHosts: ['osrm.internal.test'],
        );
    }

    private function service(
        HttpFactory $http,
        Repository $cache,
        bool $enabled = true,
        float $fallbackSpeedKmh = 60,
    ): RoutingService {
        return new RoutingService(
            provider: $this->provider($http),
            cache: $cache,
            enabled: $enabled,
            cacheTtlSeconds: 900,
            failureCacheTtlSeconds: 15,
            fallbackSpeedKmh: $fallbackSpeedKmh,
        );
    }
}
