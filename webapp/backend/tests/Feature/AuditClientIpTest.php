<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AuditClientIpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/security-contract/audit-client-ip', static function (AuditService $audit): JsonResponse {
            $entry = $audit->record(
                action: 'security.audit_client_ip_test',
                target: 'audit-client-ip',
            );

            return response()->json(['data' => ['id' => $entry->id]]);
        });
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);

        parent::tearDown();
    }

    public function test_audit_uses_forwarded_client_ip_from_a_trusted_proxy_chain(): void
    {
        config()->set('trustedproxy.proxies', ['10.0.0.0/8']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.44, 10.0.0.9')
            ->getJson('/api/security-contract/audit-client-ip')
            ->assertOk();

        $audit = AuditLog::query()->where('action', 'security.audit_client_ip_test')->sole();

        $this->assertSame('203.0.113.44', $audit->ip_address);
    }

    public function test_audit_ignores_spoofed_forwarded_ip_from_an_untrusted_source(): void
    {
        config()->set('trustedproxy.proxies', ['10.0.0.0/8']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.28'])
            ->withHeader('X-Forwarded-For', '203.0.113.99')
            ->getJson('/api/security-contract/audit-client-ip')
            ->assertOk();

        $audit = AuditLog::query()->where('action', 'security.audit_client_ip_test')->sole();

        $this->assertSame('198.51.100.28', $audit->ip_address);
    }

    public function test_trusted_proxy_addresses_are_loaded_from_configuration_for_each_request(): void
    {
        config()->set('trustedproxy.proxies', ['10.0.0.0/8']);

        $trustedResponse = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.44')
            ->getJson('/api/security-contract/audit-client-ip')
            ->assertOk();

        $trustedAudit = AuditLog::query()->findOrFail((string) $trustedResponse->json('data.id'));
        $this->assertSame('203.0.113.44', $trustedAudit->ip_address);

        config()->set('trustedproxy.proxies', ['192.0.2.0/24']);

        $untrustedResponse = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.99')
            ->getJson('/api/security-contract/audit-client-ip')
            ->assertOk();

        $untrustedAudit = AuditLog::query()->findOrFail((string) $untrustedResponse->json('data.id'));
        $this->assertSame('10.0.0.10', $untrustedAudit->ip_address);
    }
}
