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
        $clientType = $this->clientType($token);

        return match ($clientType) {
            'operator' => $user?->canUseOperatorApp() === true,
            'admin' => $user?->canUseAdminApp() === true,
            default => true,
        };
    }

    private function clientType(mixed $token): string
    {
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];
        if (in_array('client:admin', $abilities, true)) {
            return 'admin';
        }
        if (in_array('client:operator', $abilities, true)) {
            return 'operator';
        }
        if (in_array('client:web', $abilities, true)) {
            return 'web';
        }

        $tokenName = is_string($token?->name ?? null) ? strtolower($token->name) : '';
        if (str_contains($tokenName, 'admin android')) {
            return 'admin';
        }
        if (str_contains($tokenName, 'android')) {
            return 'operator';
        }

        return 'web';
    }
}
