<?php

namespace Tests\Feature;

use App\Logging\RedactSensitiveLogContext;
use App\Models\AuditLog;
use App\Services\AuditService;
use App\Support\SensitiveDataRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use RuntimeException;
use Tests\TestCase;

final class SensitiveLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_and_embedded_credentials_are_redacted_recursively(): void
    {
        $redactor = app(SensitiveDataRedactor::class);

        $redacted = $redactor->redactArray([
            'password' => 'correct horse battery staple',
            'nested' => [
                'two_factor_code' => '123456',
                'x_dis_developer_key' => 'structured-developer-secret',
                'safe' => 'visible',
                'message' => 'Authorization: Bearer bearer-secret Cookie: __Host-dis_session=session-secret X-DIS-Developer-Key: header-developer-secret',
            ],
            'url' => 'https://example.test/callback?token=query-secret&code=otp-secret',
        ]);

        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['nested']['two_factor_code']);
        $this->assertSame('visible', $redacted['nested']['safe']);
        $this->assertSensitiveValuesMissing($redacted, [
            'correct horse battery staple',
            '123456',
            'bearer-secret',
            'session-secret',
            'structured-developer-secret',
            'header-developer-secret',
            'query-secret',
            'otp-secret',
        ]);
    }

    public function test_logging_tap_redacts_message_context_and_extra_data(): void
    {
        $handler = new TestHandler;
        $monolog = new MonologLogger('security-test', [$handler]);
        $logger = new IlluminateLogger($monolog, app('events'));
        app(RedactSensitiveLogContext::class)($logger);

        $logger->warning(
            'Authorization: Bearer message-secret',
            [
                'cookie' => '__Host-dis_session=context-secret',
                'nested' => ['csrf_token' => 'csrf-secret', 'safe' => 'visible'],
            ],
        );

        $record = $handler->getRecords()[0];
        $serialized = json_encode([
            'message' => $record->message,
            'context' => $record->context,
            'extra' => $record->extra,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('[REDACTED]', $serialized);
        $this->assertStringContainsString('visible', $serialized);
        foreach (['message-secret', 'context-secret', 'csrf-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_audit_metadata_user_agent_and_reason_are_sanitized(): void
    {
        $request = Request::create('/api/security-test', 'POST');
        $request->headers->set('User-Agent', 'DIS test Cookie: __Host-dis_session=user-agent-secret');
        $request->attributes->set('request_id', 'security-request-id');

        $audit = app(AuditService::class)->record(
            action: 'security.redaction_test',
            target: 'security-test',
            metadata: [
                'authorization' => 'Bearer audit-secret',
                'nested' => ['password' => 'audit-password', 'safe' => 'visible'],
            ],
            reason: 'Cookie: __Host-dis_session=reason-secret',
            request: $request,
        );

        $serialized = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('security-request-id', $serialized);
        $this->assertStringContainsString('visible', $serialized);
        $this->assertSensitiveValuesMissing($audit->toArray(), [
            'user-agent-secret',
            'audit-secret',
            'audit-password',
            'reason-secret',
        ]);
    }

    public function test_production_api_error_does_not_expose_exception_details(): void
    {
        Route::get('/api/security-contract/error', static function (): never {
            throw new RuntimeException('database-password=error-secret at C:\\private\\Application.php:42');
        });

        $response = $this->getJson('/api/security-contract/error');

        $response->assertStatus(500)
            ->assertJsonPath('error.code', 'server_error')
            ->assertJsonPath('error.message', 'Server error.');
        $serialized = $response->getContent();
        foreach (['error-secret', 'Application.php', 'RuntimeException', 'database-password'] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }
    }

    public function test_failed_login_audit_uses_an_email_hash_instead_of_the_address(): void
    {
        $email = 'privacy-target@example.test';

        $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => 'Not-the-password-123!',
            'device_name' => 'DIS Operator Android',
            'client_type' => 'operator_android',
        ])->assertUnprocessable();

        $audit = AuditLog::query()->where('action', 'auth.login_failed')->latest('created_at')->firstOrFail();

        $this->assertSame(hash('sha256', $email), $audit->metadata['email_hash'] ?? null);
        $this->assertArrayNotHasKey('email', $audit->metadata);
        $this->assertStringNotContainsString($email, json_encode($audit->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $sensitiveValues
     */
    private function assertSensitiveValuesMissing(array $payload, array $sensitiveValues): void
    {
        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('[REDACTED]', $serialized);
        foreach ($sensitiveValues as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $serialized);
        }
    }
}
