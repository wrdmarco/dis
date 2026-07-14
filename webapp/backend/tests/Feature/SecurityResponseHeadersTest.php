<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class SecurityResponseHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/security-contract/ok', static fn () => response()->json(['data' => ['ok' => true]]));
    }

    public function test_security_headers_are_present_on_success_authentication_error_and_not_found_responses(): void
    {
        $responses = [
            $this->secureJson('GET', '/api/security-contract/ok'),
            $this->secureJson('POST', '/api/auth/login', []),
            $this->secureJson('GET', '/api/admin/settings'),
            $this->secureJson('GET', '/api/security-contract/not-found'),
        ];

        $this->assertSame([200, 422, 401, 404], array_map(
            static fn ($response): int => $response->getStatusCode(),
            $responses,
        ));

        foreach ($responses as $response) {
            $response->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy')
                ->assertHeader('Permissions-Policy')
                ->assertHeader('Cross-Origin-Opener-Policy')
                ->assertHeader('Cross-Origin-Resource-Policy')
                ->assertHeader('Strict-Transport-Security');

            $csp = (string) $response->headers->get('Content-Security-Policy');
            foreach (["default-src 'none'", "object-src 'none'", "base-uri 'none'", "frame-ancestors 'none'", "form-action 'none'"] as $directive) {
                $this->assertStringContainsString($directive, $csp);
            }
            $this->assertStringNotContainsString("'unsafe-eval'", $csp);
            $this->assertStringNotContainsString("'unsafe-inline'", $csp);

            foreach (['Server', 'X-Powered-By', 'x-nextjs-cache', 'x-nextjs-prerender', 'x-nextjs-stale-time', 'X-Served-By'] as $technologyHeader) {
                $response->assertHeaderMissing($technologyHeader);
            }
        }
    }

    public function test_authentication_and_authenticated_api_responses_are_not_cacheable(): void
    {
        $responses = [
            $this->secureJson('POST', '/api/auth/login', []),
            $this->secureJson('GET', '/api/admin/settings'),
        ];

        foreach ($responses as $response) {
            $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $cacheControl);
            $this->assertStringContainsString('no-store', $cacheControl);
        }
    }

    private function secureJson(string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withServerVariables([
            'HTTPS' => 'on',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->json($method, $uri, $data);
    }
}
