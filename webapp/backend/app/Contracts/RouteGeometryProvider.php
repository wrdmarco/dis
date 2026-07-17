<?php

namespace App\Contracts;

use App\DTO\Routing\RouteGeometry;
use App\DTO\Routing\RoutePoint;

interface RouteGeometryProvider
{
    public function isConfigured(): bool;

    /**
     * Only successful navigation routes are returned. Missing keys represent
     * an unavailable, invalid or deliberately bounded route.
     *
     * @param  array<string, RoutePoint>  $origins
     * @return array<string, RouteGeometry>
     */
    public function routeGeometriesTo(array $origins, RoutePoint $destination): array;
}
