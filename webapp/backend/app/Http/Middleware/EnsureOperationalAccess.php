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

        if ($this->isStoreReviewAccess($request)) {
            if ($user?->isStoreReviewAccount() === true) {
                return $next($request);
            }

            return ApiResponse::error('forbidden', 'The account is not active.', 403);
        }

        if ($user !== null && $this->clientType($user->currentAccessToken()) === 'admin') {
            return ApiResponse::error(
                'mobile_admin_retired',
                'Beheer is alleen beschikbaar via de beveiligde webapp.',
                403,
            );
        }

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
            'admin' => false,
            default => true,
        };
    }

    private function isStoreReviewAccess(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];

        return in_array('client:store_review', $abilities, true);
    }

    private function clientType(mixed $token): string
    {
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];
        if (in_array('client:store_review', $abilities, true)) {
            return 'store_review';
        }
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
