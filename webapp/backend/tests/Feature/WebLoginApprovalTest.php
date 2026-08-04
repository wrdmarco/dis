<?php

namespace Tests\Feature;

use App\Contracts\PushProvider;
use App\Jobs\SendFcmNotification;
use App\Models\AuditLog;
use App\Models\FcmToken;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WebLoginApproval;
use App\Models\WebLoginApprovalRecipient;
use App\Services\DispatchPushOutboxService;
use App\Services\TwoFactorService;
use App\Services\WebLoginApprovalService;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

final class WebLoginApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://dis.example.test';

    /** @var array<string, string> */
    private array $browserCookies = [];

    private ?string $csrfHeader = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::ORIGIN,
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
            'session.trusted_origins' => [self::ORIGIN],
            'sanctum.stateful' => ['dis.example.test'],
        ]);
        SystemSetting::query()->updateOrCreate(
            ['key' => TwoFactorService::REQUIRED_KEY],
            ['value' => true, 'is_sensitive' => false],
        );
        Queue::fake();
    }

    public function test_login_push_is_gated_by_explicit_device_capability(): void
    {
        $user = $this->user('capability@example.test');
        [, $device] = $this->device($user, 'capability-device', []);

        $this->initializeCsrf();
        $login = $this->login($user);
        $login->assertStatus(202)
            ->assertJsonPath('data.mobile_approval.available', false)
            ->assertJsonPath('data.mobile_approval.status', 'unavailable');
        $this->assertDatabaseCount('web_login_approvals', 0);
        Queue::assertNothingPushed();

        $device->forceFill(['capabilities' => [WebLoginApprovalService::CAPABILITY]])->save();
        $this->resetBrowserState();
        $this->initializeCsrf();
        $capableLogin = $this->login($user);
        $capableLogin->assertStatus(202)
            ->assertJsonPath('data.mobile_approval.available', true)
            ->assertJsonPath('data.mobile_approval.status', 'pending');
        $this->assertMatchesRegularExpression(
            '/^\d{3}$/',
            (string) $capableLogin->json('data.mobile_approval.verification_number'),
        );
        $this->assertDatabaseCount('web_login_approvals', 1);
        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job): bool {
            $this->assertSame(WebLoginApprovalService::PUSH_TYPE, $job->messageType);
            $this->assertSame([
                'type' => WebLoginApprovalService::PUSH_TYPE,
                'login_approval_id' => WebLoginApproval::query()->sole()->id,
            ], $job->data);

            return $job->webLoginApprovalRecipientId !== null;
        });
    }

    public function test_heartbeat_without_capabilities_clears_a_newer_builds_advertisement(): void
    {
        $user = $this->user('capability-downgrade@example.test');
        [$bearer, $device] = $this->device($user, 'capability-downgrade-device');

        $this->assertSame([WebLoginApprovalService::CAPABILITY], $device->capabilities);
        $this->mobileJson('POST', '/api/devices/heartbeat', $bearer, [
            'device_id' => $device->device_id,
            'client_type' => 'operator',
            'app_version' => 'legacy-build',
        ])
            ->assertOk()
            ->assertJsonPath('data.capabilities', []);

        $this->assertSame([], $device->refresh()->capabilities);
    }

    public function test_resend_cancels_the_old_request_and_queues_a_new_durable_request(): void
    {
        Carbon::setTestNow('2026-08-04T10:00:00Z');
        try {
            $user = $this->user('resend-details@example.test');
            [, $device] = $this->device($user, 'resend-details-device');

            $this->initializeCsrf();
            $this->login($user)->assertStatus(202);
            $oldApproval = WebLoginApproval::query()->sole();
            $oldJob = Queue::pushed(SendFcmNotification::class)->sole();
            $oldRequestedAt = now()->subHour();
            $oldApproval->forceFill([
                'status' => 'expired',
                'verification_number' => '000',
                'request_device' => 'Verouderde browser',
                'request_ip' => '198.51.100.1',
                'requested_at' => $oldRequestedAt,
                'expires_at' => now()->subMinute(),
            ])->save();
            Carbon::setTestNow('2026-08-04T10:00:16Z');

            $this->browserJson('POST', '/api/auth/2fa/mobile-approval/resend')
                ->assertOk()
                ->assertJsonPath('data.status', 'pending');

            $oldApproval->refresh();
            $resent = WebLoginApproval::query()->whereNot('id', $oldApproval->id)->sole();
            $this->assertDatabaseCount('web_login_approvals', 2);
            $this->assertSame('cancelled', $oldApproval->status);
            $this->assertNull($oldApproval->browser_session_hash);
            $this->assertNotSame($oldApproval->id, $resent->id);
            $this->assertSame('pending', $resent->status);
            $this->assertNotSame('000', $resent->verification_number);
            $this->assertTrue($resent->requested_at->greaterThan($oldRequestedAt));
            $this->assertNotSame('Verouderde browser', $resent->request_device);
            $this->assertSame('192.0.2.50', $resent->request_ip);
            Queue::assertPushed(SendFcmNotification::class, 2);
            Queue::assertPushed(
                SendFcmNotification::class,
                fn (SendFcmNotification $job): bool => ($job->data['login_approval_id'] ?? null) === $resent->id,
            );

            $provider = $this->recordingProvider();
            $oldJob->handle($provider, app(DispatchPushOutboxService::class));
            $this->assertSame(0, $provider->sendCount);
            $this->assertTrue($device->refresh()->is_active);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rate_limited_resend_leaves_the_existing_request_untouched(): void
    {
        Carbon::setTestNow('2026-08-04T10:30:00Z');
        try {
            $user = $this->user('resend-rate-limit@example.test');
            $this->device($user, 'resend-rate-limit-device');

            $this->initializeCsrf();
            $this->login($user)->assertStatus(202);
            $approval = WebLoginApproval::query()->sole();
            $originalHash = $approval->browser_session_hash;

            $this->browserJson('POST', '/api/auth/2fa/mobile-approval/resend')
                ->assertStatus(429)
                ->assertHeader('Retry-After')
                ->assertJsonPath('error.code', 'login_approval_rate_limited');

            $approval->refresh();
            $this->assertDatabaseCount('web_login_approvals', 1);
            $this->assertSame('pending', $approval->status);
            $this->assertSame($originalHash, $approval->browser_session_hash);
            Queue::assertPushed(SendFcmNotification::class, 1);
            $this->browserJson('GET', '/api/auth/2fa/mobile-approval/status')
                ->assertOk()
                ->assertJsonPath('data.status', 'pending');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_push_cooldown_and_hourly_limit_do_not_block_totp_fallback(): void
    {
        $base = Carbon::parse('2026-08-04T11:00:00Z');
        Carbon::setTestNow($base);
        try {
            $user = $this->user('push-rate-limit@example.test');
            $this->device($user, 'push-rate-limit-device');

            $this->freshBrowserLogin($user)
                ->assertStatus(202)
                ->assertJsonPath('data.mobile_approval.available', true);
            $this->freshBrowserLogin($user)
                ->assertStatus(202)
                ->assertJsonPath('data.mobile_approval.status', 'unavailable');

            for ($attempt = 2; $attempt <= 6; $attempt++) {
                Carbon::setTestNow($base->copy()->addSeconds(16 * ($attempt - 1)));
                $this->freshBrowserLogin($user)
                    ->assertStatus(202)
                    ->assertJsonPath('data.mobile_approval.available', true);
            }

            Carbon::setTestNow($base->copy()->addSeconds(96));
            $this->freshBrowserLogin($user)
                ->assertStatus(202)
                ->assertJsonPath('data.mobile_approval.status', 'unavailable');
            Queue::assertPushed(SendFcmNotification::class, 6);
            $this->assertSame(2, DB::table('audit_logs')
                ->where('actor_id', $user->id)
                ->where('action', 'auth.web_login_approval_push_rate_limited')
                ->count());

            $this->browserJson('POST', '/api/auth/2fa/verify', [
                'code' => 'LOGIN-12345',
                'device_name' => 'DIS Command Center',
                'client_type' => 'web',
            ])->assertOk()->assertJsonPath('data.authenticated', true);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_push_rate_limit_cache_failure_fails_closed_without_blocking_recovery_code(): void
    {
        $user = $this->user('push-cache-failure@example.test');
        $this->device($user, 'push-cache-failure-device');
        Cache::shouldReceive('lock')
            ->once()
            ->andThrow(new RuntimeException('Synthetic cache failure'));

        $this->initializeCsrf();
        $this->login($user)
            ->assertStatus(202)
            ->assertJsonPath('data.mobile_approval.status', 'unavailable');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('web_login_approvals', 0);

        $this->browserJson('POST', '/api/auth/2fa/verify', [
            'code' => 'LOGIN-12345',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertOk()->assertJsonPath('data.authenticated', true);
    }

    public function test_only_the_exact_targeted_full_operator_session_can_approve_and_browser_consumes_once(): void
    {
        $user = $this->user('binding@example.test');
        $user->forceFill(['two_factor_confirmed_at' => now()->subDay()])->save();
        $previousTwoFactorConfirmation = $user->two_factor_confirmed_at;
        [$targetBearer] = $this->device($user, 'target-device');

        $this->initializeCsrf();
        $this->login($user)->assertStatus(202);
        $approval = WebLoginApproval::query()->sole();
        [$otherBearer] = $this->device($user, 'other-device');

        $this->mobileJson('GET', '/api/auth/login-approvals/'.$approval->id, $otherBearer)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->mobileJson('GET', '/api/auth/login-approvals/'.$approval->id, $targetBearer)
            ->assertOk()
            ->assertJsonPath('data.id', $approval->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonStructure(['data' => [
                'id',
                'status',
                'verification_number',
                'requested_at',
                'expires_at',
                'request_device',
                'request_ip',
            ]]);

        $partialBearer = $user->createToken(
            'Pending operator',
            ['2fa:pending', 'client:operator'],
            now()->addHour(),
        )->plainTextToken;
        $this->mobileJson('POST', '/api/auth/login-approvals/'.$approval->id.'/approve', $partialBearer)
            ->assertForbidden();

        $this->mobileJson('POST', '/api/auth/login-approvals/'.$approval->id.'/approve', $targetBearer)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->mobileJson('POST', '/api/auth/2fa/mobile-approval/complete', $targetBearer)
            ->assertForbidden();

        $this->browserJson('GET', '/api/auth/2fa/mobile-approval/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $complete = $this->browserJson('POST', '/api/auth/2fa/mobile-approval/complete');
        $complete->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.user.id', $user->id);
        $this->browserJson('POST', '/api/auth/2fa/mobile-approval/complete')
            ->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('web_login_approvals', [
            'id' => $approval->id,
            'status' => 'consumed',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'auth.login_succeeded',
        ]);
        $this->assertSame(1, DB::table('audit_logs')
            ->where('actor_id', $user->id)
            ->where('action', 'auth.login_succeeded')
            ->count());
        $twoFactorAudit = AuditLog::query()
            ->where('actor_id', $user->id)
            ->where('action', 'auth.2fa_verified')
            ->sole();
        $this->assertSame('mobile_approval', $twoFactorAudit->metadata['method'] ?? null);
        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertTrue($user->two_factor_confirmed_at->greaterThan($previousTwoFactorConfirmation));
        $this->browserJson('GET', '/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_auth_session_revocation_hides_rejects_and_suppresses_a_pending_approval(): void
    {
        $user = $this->user('revoked-auth-session@example.test');
        [$targetBearer, $device] = $this->device($user, 'revoked-auth-session-device');

        $this->initializeCsrf();
        $this->login($user)->assertStatus(202);
        $approval = WebLoginApproval::query()->sole();
        $recipient = $approval->recipients()->sole();
        $user->forceFill([
            'auth_session_version' => ((int) $user->auth_session_version) + 1,
        ])->save();

        $this->mobileJson('GET', '/api/auth/login-approvals/'.$approval->id, $targetBearer)
            ->assertNotFound();
        $this->mobileJson('POST', '/api/auth/login-approvals/'.$approval->id.'/approve', $targetBearer)
            ->assertNotFound();

        $provider = $this->recordingProvider();
        $this->approvalJob($device, $approval, $recipient)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
        );
        $this->assertSame(0, $provider->sendCount);
        $this->assertDatabaseHas('web_login_approvals', [
            'id' => $approval->id,
            'status' => 'pending',
        ]);
    }

    public function test_device_that_becomes_unreachable_cannot_approve(): void
    {
        $user = $this->user('unreachable-approval@example.test');
        [$targetBearer] = $this->device($user, 'unreachable-approval-device');

        $this->initializeCsrf();
        $this->login($user)->assertStatus(202);
        $approval = WebLoginApproval::query()->sole();
        $user->forceFill(['push_enabled' => false])->save();

        $response = $this->mobileJson(
            'POST',
            '/api/auth/login-approvals/'.$approval->id.'/approve',
            $targetBearer,
        );
        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseHas('web_login_approvals', [
            'id' => $approval->id,
            'status' => 'pending',
        ]);
    }

    public function test_mobile_denial_wins_atomically_and_does_not_consume_recovery_code(): void
    {
        $user = $this->user('denied@example.test', ['DENY-12345']);
        [$targetBearer] = $this->device($user, 'denial-device');

        $this->initializeCsrf();
        $this->login($user)->assertStatus(202);
        $approval = WebLoginApproval::query()->sole();

        $this->mobileJson('POST', '/api/auth/login-approvals/'.$approval->id.'/deny', $targetBearer)
            ->assertOk()
            ->assertJsonPath('data.status', 'denied');

        $this->browserJson('POST', '/api/auth/2fa/verify', [
            'code' => 'DENY-12345',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertForbidden()->assertJsonPath('error.code', 'login_approval_denied');

        $this->assertSame(['DENY-12345'], $user->refresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('web_login_approvals', [
            'id' => $approval->id,
            'status' => 'denied',
        ]);
        $this->browserJson('GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_expired_mobile_request_leaves_totp_recovery_fallback_available(): void
    {
        $user = $this->user('expired@example.test', ['EXPIRE-12345']);
        [$targetBearer] = $this->device($user, 'expiry-device');

        $this->initializeCsrf();
        $this->login($user)->assertStatus(202);
        $approval = WebLoginApproval::query()->sole();
        $approval->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->mobileJson('POST', '/api/auth/login-approvals/'.$approval->id.'/approve', $targetBearer)
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'login_approval_expired');
        $this->browserJson('GET', '/api/auth/2fa/mobile-approval/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->browserJson('POST', '/api/auth/2fa/verify', [
            'code' => 'EXPIRE-12345',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertOk()->assertJsonPath('data.authenticated', true);
        $this->assertSame([], $user->refresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('web_login_approvals', [
            'id' => $approval->id,
            'status' => 'expired',
        ]);
    }

    public function test_queued_push_revalidates_session_capability_and_terminal_state_before_provider_call(): void
    {
        $user = $this->user('delivery-guard@example.test');
        [, $device] = $this->device($user, 'guard-device');
        $approval = WebLoginApproval::query()->create([
            'user_id' => $user->id,
            'browser_session_hash' => hash('sha256', 'guard-session'),
            'auth_session_version' => (int) $user->auth_session_version,
            'status' => 'pending',
            'verification_number' => '321',
            'request_device' => 'Testbrowser op Windows',
            'request_ip' => '192.0.2.1',
            'requested_at' => now(),
            'expires_at' => now()->addMinutes(2),
        ]);
        $recipient = $approval->recipients()->create([
            'fcm_token_id' => $device->id,
            'personal_access_token_id' => $device->personal_access_token_id,
            'delivery_status' => 'queued',
        ]);
        $provider = $this->recordingProvider();

        $this->approvalJob($device, $approval, $recipient)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
        );
        $this->assertSame(1, $provider->sendCount);

        $user->forceFill(['push_enabled' => false])->save();
        $this->approvalJob($device, $approval, $recipient)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
        );
        $this->assertSame(1, $provider->sendCount, 'Push opt-out must suppress a stale queued push.');

        $user->forceFill(['push_enabled' => true, 'account_status' => 'suspended'])->save();
        $this->approvalJob($device, $approval, $recipient)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
        );
        $this->assertSame(1, $provider->sendCount, 'Account suspension must suppress a stale queued push.');

        $user->forceFill(['account_status' => 'active'])->save();

        $device->forceFill(['capabilities' => []])->save();
        $this->approvalJob($device, $approval, $recipient)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
        );
        $this->assertSame(1, $provider->sendCount, 'Capability removal must suppress a stale queued push.');

        $device->forceFill(['capabilities' => [WebLoginApprovalService::CAPABILITY]])->save();
        $replacementToken = $user->createToken(
            'Replacement operator session',
            ['*', 'client:operator'],
            now()->addDay(),
        )->accessToken;
        $device->forceFill(['personal_access_token_id' => $replacementToken->id])->save();
        $this->approvalJob($device, $approval, $recipient)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
        );
        $this->assertSame(1, $provider->sendCount, 'Session rebinding must suppress a stale queued push.');

        $device->forceFill(['personal_access_token_id' => $recipient->personal_access_token_id])->save();
        $approval->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
        $this->approvalJob($device, $approval, $recipient)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
        );
        $this->assertSame(1, $provider->sendCount, 'A terminal approval must suppress a stale queued push.');
    }

    public function test_legacy_serialized_push_without_login_approval_recipient_remains_deliverable(): void
    {
        $user = $this->user('legacy-queued-push@example.test');
        [, $device] = $this->device($user, 'legacy-queued-push-device');
        $serialized = serialize(new SendFcmNotification(
            fcmTokenId: (string) $device->id,
            messageType: 'ordinary_update',
            title: 'Update',
            body: 'Open de app.',
            data: ['type' => 'ordinary_update'],
        ));
        $property = 'webLoginApprovalRecipientId';
        $serializedProperty = sprintf('s:%d:"%s";N;', strlen($property), $property);
        $legacySerialized = str_replace($serializedProperty, '', $serialized, $removedProperties);
        $this->assertSame(1, $removedProperties);
        $legacySerialized = preg_replace_callback(
            '/\AO:(\d+):"([^"]+)":(\d+):\{/',
            static fn (array $matches): string => sprintf(
                'O:%s:"%s":%d:{',
                $matches[1],
                $matches[2],
                ((int) $matches[3]) - 1,
            ),
            $legacySerialized,
            1,
            $rewrittenHeaders,
        );
        $this->assertSame(1, $rewrittenHeaders);
        $this->assertIsString($legacySerialized);
        $legacyJob = unserialize($legacySerialized, [
            'allowed_classes' => [SendFcmNotification::class],
        ]);
        $this->assertInstanceOf(SendFcmNotification::class, $legacyJob);
        $provider = $this->recordingProvider();

        $legacyJob->handle($provider, app(DispatchPushOutboxService::class));

        $this->assertSame(1, $provider->sendCount);
    }

    private function login(User $user): TestResponse
    {
        return $this->browserJson('POST', '/api/auth/login', [
            'email' => $user->email,
            'password' => 'Test-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ]);
    }

    /** @param list<string> $recoveryCodes */
    private function user(string $email, array $recoveryCodes = ['LOGIN-12345']): User
    {
        $user = User::query()->create([
            'name' => 'Login Approval User',
            'first_name' => 'Login',
            'last_name' => 'Approval User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => $recoveryCodes,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'login-approval-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Login approval role',
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    /**
     * @param  list<string>  $capabilities
     * @return array{string, FcmToken}
     */
    private function device(
        User $user,
        string $deviceId,
        array $capabilities = [WebLoginApprovalService::CAPABILITY],
    ): array {
        $issued = $user->createToken(
            'Operator '.$deviceId,
            ['*', 'client:operator'],
            now()->addDay(),
        );
        $device = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'device_name' => 'Test '.$deviceId,
            'token' => 'provider-'.$deviceId,
            'token_hash' => hash('sha256', 'provider-'.$deviceId),
            'personal_access_token_id' => $issued->accessToken->id,
            'platform' => 'android',
            'client_type' => 'operator',
            'capabilities' => $capabilities,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);

        return [$issued->plainTextToken, $device];
    }

    private function approvalJob(
        FcmToken $device,
        WebLoginApproval $approval,
        WebLoginApprovalRecipient $recipient,
    ): SendFcmNotification {
        return new SendFcmNotification(
            fcmTokenId: (string) $device->id,
            messageType: WebLoginApprovalService::PUSH_TYPE,
            title: 'Inlogverzoek',
            body: 'Open de app om een inlogverzoek veilig te beoordelen.',
            data: [
                'type' => WebLoginApprovalService::PUSH_TYPE,
                'login_approval_id' => (string) $approval->id,
            ],
            webLoginApprovalRecipientId: (string) $recipient->id,
        );
    }

    private function recordingProvider(): object
    {
        return new class implements PushProvider
        {
            public int $sendCount = 0;

            public function send(FcmToken $token, string $title, string $body, array $data = []): ClientResponse
            {
                $this->sendCount++;

                return new ClientResponse(new PsrResponse(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode(['name' => 'messages/login-approval'], JSON_THROW_ON_ERROR),
                ));
            }
        };
    }

    private function initializeCsrf(): void
    {
        $this->browserJson('GET', '/api/auth/csrf-cookie', includeCsrf: false)->assertNoContent();
        $this->assertNotNull($this->csrfHeader);
    }

    private function browserJson(
        string $method,
        string $uri,
        array $data = [],
        bool $includeCsrf = true,
    ): TestResponse {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        $headers = [
            'Accept' => 'application/json',
            'Origin' => self::ORIGIN,
            'Referer' => self::ORIGIN.'/',
            'Sec-Fetch-Site' => 'same-origin',
            'X-Requested-With' => 'XMLHttpRequest',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
        ];
        if ($includeCsrf && $this->csrfHeader !== null) {
            $headers['X-XSRF-TOKEN'] = $this->csrfHeader;
        }

        $response = $this->withCredentials()
            ->withUnencryptedCookies($this->browserCookies)
            ->withHeaders($headers)
            ->withServerVariables([
                'HTTP_HOST' => 'dis.example.test',
                'SERVER_NAME' => 'dis.example.test',
                'SERVER_PORT' => 443,
                'HTTPS' => 'on',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REMOTE_ADDR' => '192.0.2.50',
            ])
            ->json($method, $uri, $data);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= now()->getTimestamp()) {
                unset($this->browserCookies[$cookie->getName()]);

                continue;
            }

            $this->browserCookies[$cookie->getName()] = $cookie->getValue();
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $this->csrfHeader = rawurldecode($cookie->getValue());
            }
        }

        return $response;
    }

    private function mobileJson(
        string $method,
        string $uri,
        string $bearer,
        array $data = [],
    ): TestResponse {
        Auth::forgetGuards();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        return $this->withHeader('Accept', 'application/json')
            ->withToken($bearer)
            ->json($method, $uri, $data);
    }

    private function freshBrowserLogin(User $user): TestResponse
    {
        $this->resetBrowserState();
        $this->initializeCsrf();

        return $this->login($user);
    }

    private function resetBrowserState(): void
    {
        $this->browserCookies = [];
        $this->csrfHeader = null;
    }
}
