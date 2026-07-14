<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\PersonalAccessToken;
use App\Services\WebSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureWebSessionIsValid
{
    public function __construct(private readonly WebSessionService $webSessionService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        if ($user->currentAccessToken() instanceof PersonalAccessToken) {
            return $next($request);
        }

        if (! $this->webSessionService->isStatefulWebRequest($request)) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        $session = $request->session();
        $authenticatedAt = $session->get(WebSessionService::KEY_AUTHENTICATED_AT);
        $lastActivityAt = $session->get(WebSessionService::KEY_LAST_ACTIVITY_AT);
        $version = $session->get(WebSessionService::KEY_AUTH_VERSION);
        $now = now()->getTimestamp();
        $absoluteLifetime = max(1, (int) config('session.absolute_lifetime', 720)) * 60;
        $idleLifetime = max(1, (int) config('session.lifetime', 120)) * 60;

        if (! is_int($authenticatedAt)
            || ! is_int($lastActivityAt)
            || ! is_int($version)
            || (int) $user->auth_session_version !== $version
            || $authenticatedAt + $absoluteLifetime <= $now
            || $lastActivityAt + $idleLifetime <= $now
            || $user->account_status !== 'active') {
            $this->webSessionService->invalidate($request);

            return ApiResponse::error('session_expired', 'The web session has expired.', 401);
        }

        $session->put(WebSessionService::KEY_LAST_ACTIVITY_AT, $now);

        return $next($request);
    }
}
