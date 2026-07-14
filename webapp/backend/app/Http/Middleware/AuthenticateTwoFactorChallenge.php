<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\PersonalAccessToken;
use App\Services\WebSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateTwoFactorChallenge
{
    public function __construct(
        private readonly WebSessionService $webSessionService,
        private readonly EnsureWebSessionIsValid $ensureWebSessionIsValid,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $pendingUser = $this->webSessionService->pendingUser($request, [
            WebSessionService::PURPOSE_LOGIN_CHALLENGE,
            WebSessionService::PURPOSE_LOGIN_SETUP,
            WebSessionService::PURPOSE_REGISTRATION_SETUP,
        ]);

        if ($pendingUser !== null) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();
        if ($user === null) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        $request->setUserResolver(static fn () => $user);

        if ($user->currentAccessToken() instanceof PersonalAccessToken) {
            return $next($request);
        }

        return $this->ensureWebSessionIsValid->handle($request, $next);
    }
}
