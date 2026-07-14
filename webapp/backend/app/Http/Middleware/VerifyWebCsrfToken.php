<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWebCsrfToken extends PreventRequestForgery
{
    public function handle($request, Closure $next): Response
    {
        if (! $this->isReading($request)) {
            if (! $this->hasTrustedOrigin($request)) {
                return ApiResponse::error('origin_mismatch', 'The request origin is not allowed.', 403);
            }

            if (! $this->hasAllowedContentType($request)) {
                return ApiResponse::error('unsupported_media_type', 'The request content type is not supported.', 415);
            }
        }

        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException) {
            return ApiResponse::error('csrf_token_mismatch', 'The CSRF token is invalid or expired.', 419);
        }
    }

    protected function hasValidOrigin($request): bool
    {
        return false;
    }

    protected function runningUnitTests(): bool
    {
        return false;
    }

    private function hasTrustedOrigin(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if (is_string($fetchSite) && ! hash_equals('same-origin', strtolower($fetchSite))) {
            return false;
        }

        $candidate = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        $candidateOrigin = is_string($candidate) ? $this->normalizeOrigin($candidate) : null;

        if ($candidateOrigin === null) {
            return false;
        }

        $trustedOrigins = config('session.trusted_origins', []);
        if (! is_array($trustedOrigins)) {
            return false;
        }

        foreach ($trustedOrigins as $trustedOrigin) {
            if (is_string($trustedOrigin) && hash_equals($candidateOrigin, (string) $this->normalizeOrigin($trustedOrigin))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeOrigin(string $value): ?string
    {
        $parts = parse_url(trim($value));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.$host.':'.$port;
    }

    private function hasAllowedContentType(Request $request): bool
    {
        $hasBody = $request->getContent() !== ''
            || $request->request->count() > 0
            || $request->files->count() > 0;

        if (! $hasBody) {
            return true;
        }

        $contentType = strtolower((string) $request->headers->get('Content-Type'));

        return str_starts_with($contentType, 'application/json')
            || str_starts_with($contentType, 'multipart/form-data')
            || str_starts_with($contentType, 'application/x-www-form-urlencoded');
    }
}
