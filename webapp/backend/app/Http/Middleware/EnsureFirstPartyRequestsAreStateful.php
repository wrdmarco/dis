<?php

namespace App\Http\Middleware;

use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFirstPartyRequestsAreStateful extends EnsureFrontendRequestsAreStateful
{
    public function handle($request, $next): Response
    {
        $response = parent::handle($request, $next);

        if ($request->is('api/auth/logout') && $response->isSuccessful()) {
            $path = (string) config('session.path', '/');
            $domain = config('session.domain');
            $secure = (bool) config('session.secure', true);
            $sameSite = (string) config('session.same_site', 'lax');
            $response->headers->clearCookie(
                (string) config('session.cookie', '__Host-dis_session'),
                $path,
                is_string($domain) ? $domain : null,
                $secure,
                true,
                $sameSite,
            );
            $response->headers->clearCookie('XSRF-TOKEN', $path, null, $secure, false, $sameSite);
        }

        return $response;
    }

    public static function fromFrontend($request): bool
    {
        if (parent::fromFrontend($request)) {
            return true;
        }

        if (! hash_equals('same-origin', (string) $request->header('Sec-Fetch-Site'))) {
            return false;
        }

        if (hash_equals('XMLHttpRequest', (string) $request->header('X-Requested-With'))) {
            return true;
        }

        if (! in_array(strtoupper((string) $request->method()), ['GET', 'HEAD'], true)) {
            return false;
        }

        // Media elements cannot attach X-Requested-With. Treat only these exact,
        // read-only, same-origin delivery endpoints as stateful so Sanctum can
        // decrypt the HttpOnly web or wallboard session cookie before auth runs.
        if ($request->is(
            'api/wallboard/media/*',
            'api/wallboard/news-images/*',
            'api/admin/wallboard-media/assets/*/content',
            'api/admin/wallboard-media/assets/*/thumbnail',
            'api/admin/speech/previews/*/audio',
        )) {
            return true;
        }

        return preg_match(
            '#\Aapi/(?:'
                .'admin/speech/cache/entries/(?i:[0-9a-hjkmnp-tv-z]{26})/audio'
                .'|(?:operational-weather/radar|wallboard/weather-radar)'
                .'/(?:precipitation|lightning)/\d{8}T\d{6}Z-[a-f0-9]{16}\.png'
                .')\z#D',
            $request->path(),
        ) === 1;
    }
}
