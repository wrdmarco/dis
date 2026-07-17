<?php

namespace App\Services\Routing;

use App\Contracts\RouteGeometryProvider;
use App\DTO\Routing\RouteGeometry;
use App\DTO\Routing\RoutePoint;
use Throwable;

final class RouteGeometryService
{
    public function __construct(
        private readonly RouteGeometryProvider $provider,
        private readonly bool $enabled,
    ) {}

    /**
     * @param  array<string, RoutePoint>  $origins
     * @return array<string, RouteGeometry>
     */
    public function routesTo(array $origins, RoutePoint $destination): array
    {
        if (! $this->enabled || $origins === [] || ! $this->provider->isConfigured()) {
            return [];
        }

        try {
            return $this->provider->routeGeometriesTo($origins, $destination);
        } catch (Throwable) {
            // A map overlay is optional. Provider or network failures must not
            // make the operational live-location endpoint unavailable.
            return [];
        }
    }
}
