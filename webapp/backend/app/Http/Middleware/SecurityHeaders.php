<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $this->apply($request, $response);
    }

    public function apply(Request $request, Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if ($request->is('api/*', 'sanctum/*')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
            $responseStatus = $response->getStatusCode();
            $mediaCacheControl = (string) $response->headers->get('Cache-Control');
            $cacheableWallboardMedia = $request->routeIs(
                'wallboard-media.content',
                'wallboard-media.admin-content',
                'wallboard-media.admin-thumbnail',
            )
                && in_array($responseStatus, [200, 206, 304], true)
                && $response->headers->has('ETag')
                && str_contains($mediaCacheControl, 'private')
                && str_contains($mediaCacheControl, 'max-age=')
                && str_contains($mediaCacheControl, 'immutable')
                && ($responseStatus !== 206 || (
                    $response->headers->get('Accept-Ranges') === 'bytes'
                    && preg_match(
                        '/^bytes \d+-\d+\/\d+$/D',
                        (string) $response->headers->get('Content-Range'),
                    ) === 1
                ));
            $revalidatableWallboardContent = $request->routeIs(
                'wallboard.static',
                'wallboard.news',
                'wallboard.ticker',
            )
                && in_array($response->getStatusCode(), [200, 304], true)
                && $response->headers->has('ETag')
                && str_contains((string) $response->headers->get('Cache-Control'), 'private')
                && str_contains((string) $response->headers->get('Cache-Control'), 'no-cache')
                && str_contains(strtolower((string) $response->headers->get('Vary')), 'cookie');
            $cacheableOperationalRadar = $request->routeIs('operational-weather.radar-atlas')
                && in_array($responseStatus, [200, 304], true)
                && $response->headers->has('ETag')
                && str_contains($mediaCacheControl, 'private')
                && str_contains($mediaCacheControl, 'max-age=')
                && str_contains($mediaCacheControl, 'immutable');
            if (! $cacheableWallboardMedia && ! $revalidatableWallboardContent && ! $cacheableOperationalRadar) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Pragma', 'no-cache');
                $response->headers->set('Expires', '0');
            }
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
