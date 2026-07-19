<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\WallboardSessionService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateWallboardSession
{
    public function __construct(private readonly WallboardSessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $session = $this->sessions->authenticate($request);
        } catch (AuthenticationException) {
            return ApiResponse::error('wallboard_unauthenticated', 'Wallboard authentication is required.', 401);
        }

        /** @var Response $response */
        $response = $next($request);
        $rotatedCredential = $request->attributes->get('wallboard.rotated_credential');
        if (is_string($rotatedCredential) && $rotatedCredential !== '') {
            $response->headers->setCookie($this->sessions->cookie($rotatedCredential));
        }

        return $response;
    }
}
