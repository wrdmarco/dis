<?php

namespace Tests\Feature;

use App\Contracts\RouteGeometryProvider;
use App\Contracts\RoutingProvider;
use App\DTO\Routing\RoutePoint;
use App\DTO\Routing\RouteSource;
use App\Services\Routing\OsrmRoutingProvider;
use App\Services\Routing\RouteGeometryService;
use App\Services\Routing\RoutingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class RoutingBindingTest extends TestCase
{
    public function test_container_resolves_osrm_provider_and_routing_service(): void
    {
        config()->set([
            'dis.routing.enabled' => true,
            'dis.routing.provider' => 'osrm',
            'dis.routing.osrm.base_url' => 'http://osrm.internal.test:5000',
            'dis.routing.osrm.allowed_hosts' => 'osrm.internal.test',
        ]);
        $this->forgetRoutingSingletons();

        $this->assertInstanceOf(OsrmRoutingProvider::class, app(RoutingProvider::class));
        $this->assertSame(app(RoutingProvider::class), app(RouteGeometryProvider::class));
        $this->assertInstanceOf(RouteGeometryService::class, app(RouteGeometryService::class));
        $this->assertInstanceOf(RoutingService::class, app(RoutingService::class));
    }

    public function test_unknown_provider_name_is_fail_closed(): void
    {
        config()->set([
            'dis.routing.enabled' => true,
            'dis.routing.provider' => 'unsupported',
            'dis.routing.osrm.base_url' => 'http://osrm.internal.test:5000',
            'dis.routing.osrm.allowed_hosts' => 'osrm.internal.test',
        ]);
        $this->forgetRoutingSingletons();
        Http::fake([
            '*' => Http::response([
                'code' => 'Ok',
                'durations' => [[60]],
                'distances' => [[1000]],
            ]),
        ]);

        $route = app(RoutingService::class)->routesTo(
            ['pilot-a' => new RoutePoint(52.0907, 5.1214)],
            new RoutePoint(52.1561, 5.3878),
        )['pilot-a'];

        Http::assertNothingSent();
        $this->assertSame(RouteSource::Fallback, $route->source);
    }

    private function forgetRoutingSingletons(): void
    {
        app()->forgetInstance(RouteGeometryService::class);
        app()->forgetInstance(RouteGeometryProvider::class);
        app()->forgetInstance(RoutingService::class);
        app()->forgetInstance(RoutingProvider::class);
    }
}
