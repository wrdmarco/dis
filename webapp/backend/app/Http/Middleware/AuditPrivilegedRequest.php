<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuditPrivilegedRequest
{
    public function __construct(private readonly AuditService $auditService) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($request->user() !== null && in_array($request->method(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            $this->auditService->record(
                action: 'http.privileged_request',
                target: $request->path(),
                actor: $request->user(),
                metadata: [
                    'method' => $request->method(),
                    'status' => $response->getStatusCode(),
                    'request_id' => $request->attributes->get('request_id'),
                ],
                request: $request,
            );
        }

        return $response;
    }
}

