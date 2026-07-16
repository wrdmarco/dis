<?php

namespace App\DTO\Routing;

enum RouteSource: string
{
    case Navigation = 'navigation';
    case Fallback = 'fallback';
    case Unknown = 'unknown';
}
