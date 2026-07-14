<?php

namespace App\Http\Middleware;

use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuditRateLimitedRequest
{
    private const AUDITED_ATTRIBUTE = 'security_rate_limit_audited';

    public function __construct(private readonly AuditService $auditService) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() !== Response::HTTP_TOO_MANY_REQUESTS) {
            return $response;
        }

        $this->audit($request, $response);

        return $response;
    }

    public function audit(Request $request, Response $response): void
    {
        if ($request->attributes->getBoolean(self::AUDITED_ATTRIBUTE)) {
            return;
        }
        $request->attributes->set(self::AUDITED_ATTRIBUTE, true);

        try {
            $this->auditService->record(
                action: 'security.rate_limit_exceeded',
                target: $request->path(),
                actor: $request->user(),
                metadata: [
                    'method' => $request->method(),
                    'retry_after' => $response->headers->get('Retry-After'),
                ],
                request: $request,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
