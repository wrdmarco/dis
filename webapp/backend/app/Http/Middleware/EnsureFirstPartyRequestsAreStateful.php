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

        return hash_equals('XMLHttpRequest', (string) $request->header('X-Requested-With'))
            && hash_equals('same-origin', (string) $request->header('Sec-Fetch-Site'));
    }
}
