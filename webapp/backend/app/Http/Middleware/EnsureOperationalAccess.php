<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOperationalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->account_status !== 'active') {
            return ApiResponse::error('forbidden', 'The account is not active.', 403);
        }

        if ($user !== null && ! $this->tokenCanUseApp($request)) {
            return ApiResponse::error('app_access_denied', 'Deze rol geeft geen toegang tot deze app.', 403);
        }

        return $next($request);
    }

    private function tokenCanUseApp(Request $request): bool
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $tokenName = is_string($token?->name ?? null) ? $token->name : 'DIS API';
        $normalizedTokenName = strtolower($tokenName);

        if (str_contains($normalizedTokenName, 'admin android')) {
            return $user?->canUseAdminApp() === true;
        }

        if (str_contains($normalizedTokenName, 'android')) {
            return $user?->canUseOperatorApp() === true;
        }

        return true;
    }
}
