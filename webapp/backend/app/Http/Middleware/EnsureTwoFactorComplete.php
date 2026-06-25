<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTwoFactorComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && ($token->can('2fa:pending') || $token->can('2fa:setup'))) {
            return ApiResponse::error('two_factor_required', 'Two-factor authentication must be completed first.', 403);
        }

        if ($user !== null && ! $user->two_factor_enabled && $user->roles()->where('requires_two_factor', true)->exists()) {
            return ApiResponse::error('two_factor_setup_required', 'Two-factor authentication setup is required for this account.', 403);
        }

        return $next($request);
    }
}
