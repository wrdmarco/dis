<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null || $permissions === [] || ! collect($permissions)->contains(fn (string $permission): bool => $user->hasPermission($permission))) {
            return ApiResponse::error('forbidden', 'You do not have permission to perform this action.', 403);
        }

        return $next($request);
    }
}
