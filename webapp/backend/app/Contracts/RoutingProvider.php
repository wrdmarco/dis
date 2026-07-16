<?php

namespace App\Contracts;

use App\DTO\Routing\RouteEstimate;
use App\DTO\Routing\RoutePoint;

interface RoutingProvider
{
    public function isConfigured(): bool;

    public function cacheNamespace(): string;

    /**
     * @param  array<string, RoutePoint>  $origins
     * @return array<string, RouteEstimate>
     */
    public function routesTo(array $origins, RoutePoint $destination): array;
}
