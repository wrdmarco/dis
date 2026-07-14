<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RequirePermission
{
    public function __construct(private readonly AuditService $auditService) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null || $permissions === [] || ! collect($permissions)->contains(fn (string $permission): bool => $user->hasPermission($permission))) {
            try {
                $this->auditService->record(
                    action: 'security.permission_denied',
                    target: $request->path(),
                    actor: $user,
                    metadata: [
                        'method' => $request->method(),
                        'required_permissions' => $permissions,
                    ],
                    request: $request,
                );
            } catch (Throwable $exception) {
                report($exception);
            }

            return ApiResponse::error('forbidden', 'You do not have permission to perform this action.', 403);
        }

        return $next($request);
    }
}
