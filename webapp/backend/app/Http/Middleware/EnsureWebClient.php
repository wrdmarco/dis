<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureWebClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        $abilities = is_array($token->abilities) ? $token->abilities : [];
        if (! in_array('client:web', $abilities, true)) {
            return ApiResponse::error(
                'web_client_required',
                'Deze functie is alleen beschikbaar via de beveiligde webapp.',
                403,
            );
        }

        return $next($request);
    }
}
