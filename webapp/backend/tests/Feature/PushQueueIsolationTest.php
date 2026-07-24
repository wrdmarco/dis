<?php

namespace Tests\Feature;

use App\Contracts\PushProvider;
use App\Exceptions\TransientPushDeliveryException;
use App\Jobs\SendFcmNotification;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\Role;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\DispatchPushOutboxService;
use App\Services\FcmTokenIdentityLock;
use App\Services\PushNotificationService;
use App\Services\RevokedDevicePushQueue;
use App\Services\UserService;
use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class PushQueueIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_revocation_push_is_encrypted_and_queued_by_identifier_and_generation(): void
    {
        Queue::fake();
        $token = $this->revokedToken('queued');

        $this->assertTrue(app(RevokedDevicePushQueue::class)->enqueue(
            $token,
            'Sessie ingetrokken',
            'Je bent uitgelogd.',
        ));

        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job) use ($token): bool {
            $this->assertInstanceOf(ShouldBeEncrypted::class, $job);

            return $job->connection === 'push'
                && $job->queue === 'push'
                && $job->fcmTokenId === (string) $token->id
                && $job->expectedRevocationGeneration === $token->revocation_generation;
        });
        $this->assertDatabaseHas('fcm_tokens', ['id' => $token->id]);
    }

    public function test_legacy_serialized_job_strips_retired_server_tts_data_before_provider_delivery(): void
    {
        $user = $this->user('legacy-server-tts-data', true);
        $accessToken = $user->createToken(
            'Legacy server TTS payload',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;
        $token = $this->activeToken(
            $user,
            'legacy-server-tts-device',
            'legacy-server-tts-provider-token',
            (string) $accessToken->id,
        );
        $legacyData = [
            'type' => 'manual_admin',
            'action_mode' => 'availability',
            'push.template.title_key' => 'incident_title',
            'speech_manifest_id' => (string) Str::ulid(),
            'speech_phase' => 'attendance',
            'speech_manifest_url' => '/api/speech/manifests/legacy',
            'speech_manifest_version' => '1',
            'speech_locale' => 'nl-NL',
        ];
        $serialized = serialize(new SendFcmNotification(
            (string) $token->id,
            'manual_admin',
            'Legacy payload',
            'Open de app.',
            $legacyData,
        ));
        $job = unserialize($serialized, [
            'allowed_classes' => [SendFcmNotification::class],
        ]);
        $this->assertInstanceOf(SendFcmNotification::class, $job);
        $provider = $this->recordingProvider();

        $job->handle($provider, app(DispatchPushOutboxService::class));

        $this->assertSame(1, $provider->sendCount);
        $this->assertSame('manual_admin', $provider->lastData['type'] ?? null);
        $this->assertSame('availability', $provider->lastData['action_mode'] ?? null);
        $this->assertSame(
            'incident_title',
            $provider->lastData['push.template.title_key'] ?? null,
        );
        foreach ([
            'speech_manifest_id',
            'speech_phase',
            'speech_manifest_url',
            'speech_manifest_version',
            'speech_locale',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $provider->lastData);
        }
    }

    public function test_old_revocation_job_cannot_send_after_reactivation_or_a_new_revocation(): void
    {
        $token = $this->revokedToken('generation');
        $accessToken = $token->user->createToken(
            'Revocation binding test',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;
        $token->forceFill(['personal_access_token_id' => $accessToken->id])->save();
        $oldGeneration = (string) $token->revocation_generation;
        $oldJob = $this->revocationJob($token, $oldGeneration);
        $provider = $this->recordingProvider();

        $token->forceFill([
            'is_active' => true,
            'revoked_at' => null,
            'revocation_generation' => null,
        ])->save();
        $oldJob->handle($provider, app(DispatchPushOutboxService::class));
        $this->assertSame(0, $provider->sendCount);

        $newGeneration = (string) Str::ulid();
        $token->forceFill([
            'is_active' => false,
            'revoked_at' => now(),
            'revocation_generation' => $newGeneration,
        ])->save();
        $oldJob->handle($provider, app(DispatchPushOutboxService::class));
        $this->assertSame(0, $provider->sendCount);
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $token->id,
            'revocation_generation' => $newGeneration,
        ]);

        $this->revocationJob($token, $newGeneration)
            ->handle($provider, app(DispatchPushOutboxService::class));
        $this->assertSame(1, $provider->sendCount);
        $this->assertSame(
            (string) $accessToken->id,
            $provider->lastData['session_token_id'] ?? null,
        );
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $token->id,
            'is_active' => false,
            'revocation_generation' => null,
        ]);
    }

    public function test_admin_activation_and_mobile_registration_make_an_old_revocation_job_stale(): void
    {
        $identityLock = $this->immediateIdentityLock();
        $this->app->instance(FcmTokenIdentityLock::class, $identityLock);
        $provider = $this->recordingProvider();

        $activatedToken = $this->revokedToken('service-activation');
        $activationAccess = $activatedToken->user->createToken(
            'Service activation',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $activatedToken->forceFill([
            'personal_access_token_id' => $activationAccess->accessToken->id,
        ])->save();
        $activationGeneration = (string) $activatedToken->revocation_generation;
        app(PushNotificationService::class)->activateToken($activatedToken, null);

        $this->revocationJob($activatedToken, $activationGeneration)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
            $identityLock,
        );
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $activatedToken->id,
            'is_active' => true,
            'revocation_generation' => null,
        ]);

        $registeredToken = $this->revokedToken('service-registration');
        $registrationAccess = $registeredToken->user->createToken(
            'Service registration',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $registrationGeneration = (string) $registeredToken->revocation_generation;
        app(DeviceService::class)->registerFcmToken($registeredToken->user, [
            'device_id' => $registeredToken->device_id,
            'token' => 'replacement-provider-token',
            'platform' => 'android',
            'client_type' => 'operator',
            'device_type' => 'phone',
            'device_name' => 'Replacement phone',
            'app_version' => 'test',
        ], $registrationAccess->accessToken);

        $this->revocationJob($registeredToken, $registrationGeneration)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
            $identityLock,
        );
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $registeredToken->id,
            'is_active' => true,
            'revocation_generation' => null,
        ]);
        $this->assertSame(0, $provider->sendCount);
    }

    public function test_activation_and_registration_cannot_mutate_device_state_during_revocation_provider_io(): void
    {
        $identityLock = $this->immediateIdentityLock();
        $this->app->instance(FcmTokenIdentityLock::class, $identityLock);
        $token = $this->revokedToken('provider-io');
        $access = $token->user->createToken(
            'Provider IO',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $token->forceFill(['personal_access_token_id' => $access->accessToken->id])->save();
        $generation = (string) $token->revocation_generation;
        $activationBlocked = false;
        $registrationBlocked = false;

        $provider = $this->recordingProvider(function () use (
            $token,
            $access,
            $generation,
            &$activationBlocked,
            &$registrationBlocked,
        ): void {
            try {
                app(PushNotificationService::class)->activateToken($token->refresh(), null);
                $this->fail('Activation unexpectedly acquired the revocation delivery lock.');
            } catch (TransientPushDeliveryException) {
                $activationBlocked = true;
            }

            try {
                app(DeviceService::class)->registerFcmToken($token->user, [
                    'device_id' => 'provider-io-new-device',
                    'token' => $token->token,
                    'platform' => 'android',
                    'client_type' => 'operator',
                    'device_type' => 'phone',
                    'device_name' => 'Provider IO phone',
                    'app_version' => 'test',
                ], $access->accessToken);
                $this->fail('Registration unexpectedly acquired the revocation delivery lock.');
            } catch (TransientPushDeliveryException) {
                $registrationBlocked = true;
            }

            $this->assertDatabaseHas('fcm_tokens', [
                'id' => $token->id,
                'is_active' => false,
                'revocation_generation' => $generation,
            ]);
        });

        $this->revocationJob($token, $generation)->handle(
            $provider,
            app(DispatchPushOutboxService::class),
            $identityLock,
        );

        $this->assertTrue($activationBlocked);
        $this->assertTrue($registrationBlocked);
        $this->assertSame(1, $provider->sendCount);
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $token->id,
            'is_active' => false,
            'revocation_generation' => null,
        ]);
    }

    public function test_every_push_carries_only_the_current_safe_session_binding(): void
    {
        $user = $this->user('session-binding', true);
        $accessToken = $user->createToken(
            'Session binding test',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;
        $token = $this->activeToken(
            $user,
            'session-binding-device',
            'session-binding-provider-token',
            (string) $accessToken->id,
        );
        $provider = $this->recordingProvider();

        (new SendFcmNotification(
            (string) $token->id,
            'manual_admin',
            'Test',
            'Test',
            [
                'type' => 'manual_admin',
                // A queued payload can never inject or retain another binding.
                'session_token_id' => 'spoofed',
            ],
        ))->handle($provider, app(DispatchPushOutboxService::class));

        $this->assertSame(1, $provider->sendCount);
        $this->assertSame((string) $accessToken->id, $provider->lastData['session_token_id'] ?? null);
        $this->assertArrayNotHasKey('token', $provider->lastData);
        $this->assertArrayNotHasKey('token_hash', $provider->lastData);
    }

    public function test_cross_user_provider_token_claim_invalidates_old_jobs_and_transfers_session_safely(): void
    {
        $identityLock = $this->immediateIdentityLock();
        $this->app->instance(FcmTokenIdentityLock::class, $identityLock);
        $providerToken = 'globally-shared-provider-token';
        $oldUser = $this->user('provider-owner-old', true);
        $oldAccess = $oldUser->createToken(
            'Old provider owner',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $oldToken = $this->activeToken(
            $oldUser,
            'old-provider-device',
            $providerToken,
            (string) $oldAccess->accessToken->id,
        );
        $legacyAdminAccess = $oldUser->createToken(
            'Legacy admin provider owner',
            ['*', 'client:admin'],
            now()->addHour(),
        );
        $legacyAdminToken = $this->activeToken(
            $oldUser,
            'old-admin-device',
            'old-admin-provider-token',
            (string) $legacyAdminAccess->accessToken->id,
            'admin',
        );
        $oldOrdinaryJob = new SendFcmNotification(
            (string) $oldToken->id,
            'manual_admin',
            'Old message',
            'Must not cross account boundary.',
            ['type' => 'manual_admin'],
        );
        $oldRevocationGeneration = (string) Str::ulid();
        $oldToken->forceFill(['revocation_generation' => $oldRevocationGeneration])->save();
        $oldRevocationJob = $this->revocationJob($oldToken, $oldRevocationGeneration);

        $newUser = $this->user('provider-owner-new', false);
        $newAccess = $newUser->createToken(
            'New provider owner',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $newToken = app(DeviceService::class)->registerFcmToken($newUser, [
            'device_id' => 'new-provider-device',
            'token' => $providerToken,
            'platform' => 'android',
            'client_type' => 'operator',
            'device_type' => 'phone',
            'device_name' => 'New owner phone',
            'app_version' => 'test',
        ], $newAccess->accessToken);

        $this->assertNotSame((string) $oldToken->id, (string) $newToken->id);
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $oldToken->id,
            'user_id' => $oldUser->id,
            'is_active' => false,
            'revocation_generation' => null,
        ]);
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $newToken->id,
            'user_id' => $newUser->id,
            'is_active' => true,
            'personal_access_token_id' => $newAccess->accessToken->id,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldAccess->accessToken->id]);
        $this->assertTrue($legacyAdminToken->refresh()->is_active);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $legacyAdminAccess->accessToken->id]);
        $this->assertFalse($oldUser->refresh()->push_enabled);
        $this->assertSame(
            1,
            FcmToken::query()
                ->where('platform', 'android')
                ->where('token_hash', hash('sha256', $providerToken))
                ->where('is_active', true)
                ->count(),
        );

        $provider = $this->recordingProvider();
        $oldOrdinaryJob->handle($provider, app(DispatchPushOutboxService::class), $identityLock);
        $oldRevocationJob->handle($provider, app(DispatchPushOutboxService::class), $identityLock);
        $this->assertSame(0, $provider->sendCount);

        (new SendFcmNotification(
            (string) $newToken->id,
            'manual_admin',
            'New message',
            'Bound to the new session.',
            ['type' => 'manual_admin'],
        ))->handle($provider, app(DispatchPushOutboxService::class), $identityLock);
        $this->assertSame(1, $provider->sendCount);
        $this->assertSame(
            (string) $newAccess->accessToken->id,
            $provider->lastData['session_token_id'] ?? null,
        );
    }

    public function test_provider_rejection_cannot_deactivate_a_row_that_was_re_registered_during_io(): void
    {
        $identityLock = $this->immediateIdentityLock();
        $user = $this->user('conditional-invalidation', true);
        $oldAccess = $user->createToken('Old binding', ['*', 'client:operator'], now()->addHour());
        $newAccess = $user->createToken('New binding', ['*', 'client:operator'], now()->addHour());
        $token = $this->activeToken(
            $user,
            'conditional-invalidation-device',
            'old-invalid-provider-token',
            (string) $oldAccess->accessToken->id,
        );
        $newProviderToken = 'new-valid-provider-token';
        $provider = $this->recordingProvider(
            function () use ($token, $newProviderToken, $newAccess): void {
                // Deterministically simulates an out-of-band state transition
                // that does not cooperate with the distributed lock.
                FcmToken::query()->whereKey($token->id)->update([
                    'token' => $newProviderToken,
                    'token_hash' => hash('sha256', $newProviderToken),
                    'personal_access_token_id' => $newAccess->accessToken->id,
                    'is_active' => true,
                    'revoked_at' => null,
                    'updated_at' => now(),
                ]);
            },
            404,
            [
                'error' => [
                    'status' => 'NOT_FOUND',
                    'details' => [['errorCode' => 'UNREGISTERED']],
                ],
            ],
        );

        (new SendFcmNotification(
            (string) $token->id,
            'manual_admin',
            'Test',
            'Test',
            ['type' => 'manual_admin'],
        ))->handle($provider, app(DispatchPushOutboxService::class), $identityLock);

        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $token->id,
            'token_hash' => hash('sha256', $newProviderToken),
            'personal_access_token_id' => $newAccess->accessToken->id,
            'is_active' => true,
            'revoked_at' => null,
        ]);
        $this->assertTrue($user->refresh()->push_enabled);
    }

    public function test_admin_activation_cannot_claim_a_provider_token_owned_by_an_active_device(): void
    {
        $providerToken = 'admin-activation-conflict-token';
        $owner = $this->user('activation-owner', true);
        $this->activeToken($owner, 'activation-owner-device', $providerToken);
        $stale = $this->revokedToken('activation-stale');
        $staleAccess = $stale->user->createToken(
            'Activation stale binding',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $stale->forceFill([
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'personal_access_token_id' => $staleAccess->accessToken->id,
        ])->save();

        try {
            app(PushNotificationService::class)->activateToken($stale, null);
            $this->fail('Admin activation unexpectedly stole an active provider token.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('token', $exception->errors());
        }

        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $stale->id,
            'is_active' => false,
        ]);
        $this->assertSame(
            1,
            FcmToken::query()
                ->where('platform', 'android')
                ->where('token_hash', hash('sha256', $providerToken))
                ->where('is_active', true)
                ->count(),
        );
    }

    public function test_legacy_admin_device_neither_enables_nor_preserves_operator_push_state(): void
    {
        Queue::fake();
        $this->app->instance(FcmTokenIdentityLock::class, $this->immediateIdentityLock());
        $user = $this->user('legacy-admin-device', false);
        $legacyAdminAccess = $user->createToken(
            'Legacy admin activation',
            ['*', 'client:admin'],
            now()->addHour(),
        );
        $legacyAdminToken = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'legacy-admin-device',
            'token' => 'legacy-admin-device-provider-token',
            'token_hash' => hash('sha256', 'legacy-admin-device-provider-token'),
            'personal_access_token_id' => $legacyAdminAccess->accessToken->id,
            'platform' => 'android',
            'client_type' => 'admin',
            'is_active' => false,
            'last_seen_at' => now(),
            'revoked_at' => now(),
        ]);

        app(PushNotificationService::class)->activateToken($legacyAdminToken, null);

        $this->assertTrue($legacyAdminToken->refresh()->is_active);
        $this->assertFalse($user->refresh()->push_enabled);

        $operatorAccess = $user->createToken(
            'Operator revocation',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $operatorToken = $this->activeToken(
            $user,
            'legacy-admin-operator-device',
            'legacy-admin-operator-provider-token',
            (string) $operatorAccess->accessToken->id,
        );
        $user->forceFill(['push_enabled' => true])->save();
        DB::table('availability_statuses')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => 'available',
            'is_available' => true,
            'is_system_applied' => false,
            'effective_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        app(PushNotificationService::class)->revokeToken($operatorToken, null);

        $this->assertFalse($operatorToken->refresh()->is_active);
        $this->assertTrue($legacyAdminToken->refresh()->is_active);
        $this->assertFalse($user->refresh()->push_enabled);
        $this->assertFalse((bool) DB::table('availability_statuses')
            ->where('user_id', $user->id)
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('is_available'));
    }

    public function test_same_user_admin_claim_of_operator_provider_token_disables_operator_reachability(): void
    {
        $this->app->instance(FcmTokenIdentityLock::class, $this->immediateIdentityLock());
        $providerToken = 'same-user-cross-client-provider-token';
        $user = $this->user('same-user-cross-client-claim', true);
        $operatorAccess = $user->createToken(
            'Operator provider owner',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $operatorToken = $this->activeToken(
            $user,
            'same-user-operator-device',
            $providerToken,
            (string) $operatorAccess->accessToken->id,
        );
        $adminAccess = $user->createToken(
            'Legacy admin provider claimant',
            ['*', 'client:admin'],
            now()->addHour(),
        );
        DB::table('availability_statuses')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => 'available',
            'is_available' => true,
            'is_system_applied' => false,
            'effective_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $adminToken = app(DeviceService::class)->registerFcmToken($user, [
            'device_id' => 'same-user-admin-device',
            'token' => $providerToken,
            'platform' => 'android',
            'client_type' => 'admin',
            'device_type' => 'phone',
            'device_name' => 'Legacy admin phone',
            'app_version' => 'test',
        ], $adminAccess->accessToken);

        $this->assertNotSame((string) $operatorToken->id, (string) $adminToken->id);
        $this->assertFalse($operatorToken->refresh()->is_active);
        $this->assertTrue($adminToken->refresh()->is_active);
        $this->assertSame('admin', $adminToken->client_type);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $operatorAccess->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $adminAccess->accessToken->id,
        ]);
        $this->assertFalse($user->refresh()->push_enabled);
        $this->assertFalse((bool) DB::table('availability_statuses')
            ->where('user_id', $user->id)
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('is_available'));
        $this->assertSame(0, $user->fcmTokens()
            ->where('client_type', 'operator')
            ->where('is_active', true)
            ->count());
        $this->assertSame(1, FcmToken::query()
            ->where('platform', 'android')
            ->where('token_hash', hash('sha256', $providerToken))
            ->where('is_active', true)
            ->where('client_type', 'admin')
            ->count());
    }

    public function test_device_revocation_is_idempotent_preserves_new_sessions_and_cannot_be_reactivated(): void
    {
        Queue::fake();
        $user = $this->user('idempotent-device-revoke', true);
        $linkedAccess = $user->createToken(
            'Linked device session',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $newAccess = $user->createToken(
            'New unregistered session',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $legacyAdminAccess = $user->createToken(
            'Legacy admin session',
            ['*', 'client:admin'],
            now()->addHour(),
        );
        $token = $this->activeToken(
            $user,
            'idempotent-device',
            'idempotent-provider-token',
            (string) $linkedAccess->accessToken->id,
        );
        $legacyAdminToken = $this->activeToken(
            $user,
            'idempotent-admin-device',
            'idempotent-admin-provider-token',
            (string) $legacyAdminAccess->accessToken->id,
            'admin',
        );

        app(DeviceService::class)->revokeFcmToken($user, $token);
        $firstGeneration = (string) $token->refresh()->revocation_generation;
        app(DeviceService::class)->revokeFcmToken($user, $token);

        $this->assertNotSame('', $firstGeneration);
        $this->assertSame($firstGeneration, (string) $token->refresh()->revocation_generation);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $linkedAccess->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $newAccess->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $legacyAdminAccess->accessToken->id,
        ]);
        $this->assertTrue($legacyAdminToken->refresh()->is_active);
        $this->assertFalse($user->refresh()->push_enabled);
        Queue::assertPushed(SendFcmNotification::class, 1);

        try {
            app(PushNotificationService::class)->activateToken($token, null);
            $this->fail('A revoked device without its bound session was reactivated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('token', $exception->errors());
        }

        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $token->id,
            'is_active' => false,
            'revocation_generation' => $firstGeneration,
        ]);
    }

    public function test_ordinary_push_deactivates_a_device_with_a_deleted_session_binding(): void
    {
        $user = $this->user('deleted-binding', true);
        $access = $user->createToken(
            'Deleted binding',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $token = $this->activeToken(
            $user,
            'deleted-binding-device',
            'deleted-binding-provider-token',
            (string) $access->accessToken->id,
        );
        $legacyAdminAccess = $user->createToken(
            'Deleted binding legacy admin',
            ['*', 'client:admin'],
            now()->addHour(),
        );
        $legacyAdminToken = $this->activeToken(
            $user,
            'deleted-binding-admin-device',
            'deleted-binding-admin-provider-token',
            (string) $legacyAdminAccess->accessToken->id,
            'admin',
        );
        DB::table('availability_statuses')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => 'available',
            'is_available' => true,
            'is_system_applied' => false,
            'effective_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        $access->accessToken->delete();
        $provider = $this->recordingProvider();

        (new SendFcmNotification(
            (string) $token->id,
            'manual_admin',
            'Sensitive title',
            'Sensitive body',
            ['type' => 'manual_admin'],
        ))->handle(
            $provider,
            app(DispatchPushOutboxService::class),
            $this->immediateIdentityLock(),
        );

        $this->assertSame(0, $provider->sendCount);
        $this->assertFalse($token->refresh()->is_active);
        $this->assertTrue($legacyAdminToken->refresh()->is_active);
        $this->assertFalse($user->refresh()->push_enabled);
        $this->assertFalse((bool) DB::table('availability_statuses')
            ->where('user_id', $user->id)
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('is_available'));
    }

    public function test_user_revocation_lock_blocks_registration_and_stale_pat_cannot_resume_after_revoke(): void
    {
        Queue::fake();
        $identityLock = $this->immediateIdentityLock();
        $this->app->instance(FcmTokenIdentityLock::class, $identityLock);
        $user = $this->user('user-revoke-race', true);
        $access = $user->createToken(
            'User revoke race',
            ['*', 'client:operator'],
            now()->addHour(),
        );
        $token = $this->activeToken(
            $user,
            'user-revoke-race-device',
            'user-revoke-race-provider',
            (string) $access->accessToken->id,
        );
        $heldLock = Cache::lock(
            FcmTokenIdentityLock::userKey((string) $user->id),
            FcmTokenIdentityLock::LOCK_SECONDS,
        );
        $this->assertTrue($heldLock->get());

        try {
            app(UserService::class)->revokeAuthenticationState(
                $user,
                $user,
                'test.user_sessions_revoked',
            );
            $this->fail('Authentication revocation unexpectedly bypassed the user lock.');
        } catch (TransientPushDeliveryException) {
            $this->assertTrue(true);
        }

        try {
            app(DeviceService::class)->registerFcmToken($user, [
                'device_id' => 'user-revoke-race-other-device',
                'token' => 'user-revoke-race-other-provider',
                'platform' => 'android',
                'client_type' => 'operator',
                'device_type' => 'phone',
                'device_name' => 'Concurrent registration',
                'app_version' => 'test',
            ], $access->accessToken);
            $this->fail('Device registration unexpectedly bypassed the user lock.');
        } catch (TransientPushDeliveryException) {
            $this->assertTrue(true);
        } finally {
            $heldLock->release();
        }

        app(UserService::class)->revokeAuthenticationState(
            $user,
            $user,
            'test.user_sessions_revoked',
        );
        $this->assertFalse($token->refresh()->is_active);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $access->accessToken->id,
        ]);

        try {
            app(DeviceService::class)->registerFcmToken($user, [
                'device_id' => 'user-revoke-race-new-device',
                'token' => 'user-revoke-race-new-provider',
                'platform' => 'android',
                'client_type' => 'operator',
                'device_type' => 'phone',
                'device_name' => 'Stale authenticated request',
                'app_version' => 'test',
            ], $access->accessToken);
            $this->fail('A deleted personal access token re-registered a device.');
        } catch (AuthenticationException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseMissing('fcm_tokens', [
            'device_id' => 'user-revoke-race-new-device',
            'is_active' => true,
        ]);
    }

    public function test_revocation_completion_and_final_failure_preserve_token_and_outbox_history(): void
    {
        [$token, $outbox] = $this->revokedOutboxFixture('retention-history');
        $generation = (string) $token->revocation_generation;
        $job = $this->revocationJob($token, $generation);

        $job->handle(
            $this->recordingProvider(),
            app(DispatchPushOutboxService::class),
            $this->immediateIdentityLock(),
        );
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $token->id,
            'revocation_generation' => null,
        ]);
        $this->assertDatabaseHas('dispatch_push_outbox', ['id' => $outbox->id]);

        $failedToken = $this->revokedToken('final-failure');
        $failedGeneration = (string) $failedToken->revocation_generation;
        $this->revocationJob($failedToken, $failedGeneration)->failed(new RuntimeException('test'));
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $failedToken->id,
            'revocation_generation' => $failedGeneration,
        ]);

        $token->forceFill(['revoked_at' => now()->subDays(2)])->save();
        $this->artisan('dis:prune-operational-data')->assertSuccessful();
        $this->assertDatabaseHas('fcm_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('dispatch_push_outbox', ['id' => $outbox->id]);
    }

    public function test_device_state_lock_exceeds_worker_and_retry_leases(): void
    {
        $service = file_get_contents(dirname(__DIR__, 4).'/infrastructure/systemd/dis-push@.service');
        $this->assertIsString($service);
        $this->assertMatchesRegularExpression('/--timeout=(\d+)/', $service);
        preg_match('/--timeout=(\d+)/', $service, $matches);
        $workerTimeout = (int) ($matches[1] ?? 0);

        $this->assertGreaterThan($workerTimeout, FcmTokenIdentityLock::LOCK_SECONDS);
        $this->assertGreaterThan(
            (int) config('queue.connections.push.retry_after'),
            FcmTokenIdentityLock::LOCK_SECONDS,
        );
    }

    public function test_provider_token_deduplication_migration_forces_losing_user_unavailable(): void
    {
        $migration = require database_path(
            'migrations/2026_07_24_000009_enforce_unique_active_push_provider_tokens.php',
        );
        $migration->down();
        $migrationRestored = false;

        try {
            $losingUser = $this->user('migration-loser', true);
            $winningUser = $this->user('migration-winner', true);
            DB::table('availability_statuses')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => $losingUser->id,
                'user_name' => $losingUser->name,
                'user_email' => $losingUser->email,
                'status' => 'available',
                'is_available' => true,
                'is_system_applied' => false,
                'effective_at' => now()->subMinute(),
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ]);

            $providerToken = 'migration-duplicate-provider-token';
            $losingAccess = $losingUser->createToken(
                'Migration losing binding',
                ['*', 'client:operator'],
                now()->addHour(),
            );
            $losingToken = $this->activeToken(
                $losingUser,
                'migration-losing-device',
                $providerToken,
                (string) $losingAccess->accessToken->id,
            );
            $losingToken->forceFill(['last_seen_at' => now()->subMinute()])->save();
            $winningAccess = $winningUser->createToken(
                'Migration winning binding',
                ['*', 'client:operator'],
                now()->addHour(),
            );
            $winningToken = $this->activeToken(
                $winningUser,
                'migration-winning-device',
                $providerToken,
                (string) $winningAccess->accessToken->id,
            );

            $migration->up();
            $migrationRestored = true;

            $this->assertFalse($losingToken->refresh()->is_active);
            $this->assertTrue($winningToken->refresh()->is_active);
            $this->assertFalse($losingUser->refresh()->push_enabled);
            $this->assertTrue($winningUser->refresh()->push_enabled);
            $this->assertDatabaseHas('availability_statuses', [
                'user_id' => $losingUser->id,
                'status' => 'unavailable',
                'is_available' => false,
                'is_system_applied' => true,
                'reason' => 'Push notifications disabled.',
            ]);
            $this->assertSame(
                'unavailable',
                DB::table('availability_statuses')
                    ->where('user_id', $losingUser->id)
                    ->orderByDesc('effective_at')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->value('status'),
            );
        } finally {
            if (! $migrationRestored) {
                $migration->up();
            }
        }
    }

    public function test_busy_registration_returns_sanitized_retryable_503(): void
    {
        $identityLock = $this->immediateIdentityLock();
        $this->app->instance(FcmTokenIdentityLock::class, $identityLock);
        $user = $this->user('busy-http', false);
        $role = Role::query()->create([
            'name' => 'busy-http-operator',
            'display_name' => 'Busy HTTP operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);
        $access = $user->createToken('Busy HTTP', ['*', 'client:operator'], now()->addHour());
        $token = $this->activeToken(
            $user,
            'busy-http-device',
            'busy-http-provider-token',
            (string) $access->accessToken->id,
        );
        $heldLock = Cache::lock(
            FcmTokenIdentityLock::keyForToken($token),
            FcmTokenIdentityLock::LOCK_SECONDS,
        );
        $this->assertTrue($heldLock->get());

        try {
            Auth::forgetGuards();
            $response = $this->withToken($access->plainTextToken)
                ->postJson('/api/devices/fcm-token', [
                    'device_id' => $token->device_id,
                    'token' => 'busy-http-provider-token',
                    'platform' => 'android',
                    'client_type' => 'operator',
                    'device_type' => 'phone',
                    'device_name' => 'Busy HTTP phone',
                    'app_version' => 'test',
                ]);
        } finally {
            $heldLock->release();
        }

        $response
            ->assertStatus(503)
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('error.code', 'push_device_state_busy')
            ->assertJsonMissingPath('error.details.lock_key');
        $this->assertStringNotContainsString((string) $token->device_id, $response->getContent());
        $this->assertStringNotContainsString((string) $token->token_hash, $response->getContent());
    }

    public function test_revocation_job_retries_without_sending_when_device_identity_lock_is_busy(): void
    {
        $identityLock = $this->immediateIdentityLock();
        $token = $this->revokedToken('lock-timeout');
        $generation = (string) $token->revocation_generation;
        $provider = $this->recordingProvider();
        $heldLock = Cache::lock(
            FcmTokenIdentityLock::keyForToken($token),
            FcmTokenIdentityLock::LOCK_SECONDS,
        );
        $this->assertTrue($heldLock->get());

        try {
            $this->revocationJob($token, $generation)->handle(
                $provider,
                app(DispatchPushOutboxService::class),
                $identityLock,
            );
            $this->fail('The revocation job unexpectedly acquired a busy device identity lock.');
        } catch (TransientPushDeliveryException $exception) {
            $this->assertStringContainsString('temporarily locked', $exception->getMessage());
        } finally {
            $heldLock->release();
        }

        $this->assertSame(0, $provider->sendCount);
        $this->assertDatabaseHas('fcm_tokens', [
            'id' => $token->id,
            'is_active' => false,
            'revocation_generation' => $generation,
        ]);
    }

    public function test_revocation_workflows_do_not_call_a_provider_synchronously(): void
    {
        $repositoryRoot = dirname(__DIR__, 4);
        foreach ([
            'DeviceService.php',
            'PushNotificationService.php',
            'UserService.php',
        ] as $service) {
            $source = file_get_contents(
                $repositoryRoot.'/webapp/backend/app/Services/'.$service,
            );
            $this->assertIsString($source);
            $this->assertStringContainsString('revokedDevicePush->enqueue(', $source);
            $this->assertStringNotContainsString('pushClient->send(', $source);
            $this->assertStringNotContainsString('fcmClient->send(', $source);
        }
    }

    public function test_abandoned_revoked_token_is_pruned_without_deleting_outbox_history(): void
    {
        $abandoned = $this->revokedToken('abandoned');
        $abandoned->forceFill(['revoked_at' => now()->subDays(2)])->save();

        $this->artisan('dis:prune-operational-data')->assertSuccessful();

        $this->assertDatabaseMissing('fcm_tokens', ['id' => $abandoned->id]);
    }

    private function user(string $suffix, bool $pushEnabled): User
    {
        return User::query()->create([
            'name' => 'Push Queue '.$suffix,
            'first_name' => 'Push',
            'last_name' => 'Queue '.$suffix,
            'email' => "push-queue-{$suffix}@example.test",
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => $pushEnabled,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function activeToken(
        User $user,
        string $deviceId,
        string $providerToken,
        ?string $personalAccessTokenId = null,
        string $clientType = 'operator',
    ): FcmToken {
        return FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'personal_access_token_id' => $personalAccessTokenId,
            'platform' => 'android',
            'client_type' => $clientType,
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }

    private function revokedToken(string $suffix): FcmToken
    {
        $user = $this->user($suffix, false);

        return FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'push-queue-'.$suffix,
            'token' => 'provider-token-'.$suffix,
            'token_hash' => hash('sha256', 'provider-token-'.$suffix),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => false,
            'last_seen_at' => now(),
            'revoked_at' => now(),
            'revocation_generation' => (string) Str::ulid(),
        ]);
    }

    /** @return array{FcmToken, DispatchPushOutbox} */
    private function revokedOutboxFixture(string $suffix): array
    {
        $token = $this->revokedToken($suffix);
        $user = $token->user;
        $incident = Incident::query()->create([
            'reference' => 'PUSH-'.strtoupper($suffix),
            'title' => 'Push retention test',
            'priority' => 'normal',
            'status' => 'dispatching',
            'is_test' => false,
            'created_by' => $user->id,
            'created_by_name' => $user->name,
            'created_by_email' => $user->email,
            'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $user->id,
            'requested_by_name' => $user->name,
            'requested_by_email' => $user->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Push retention test',
            'sent_at' => now(),
        ]);
        $outbox = DispatchPushOutbox::query()->create([
            'deduplication_key' => hash('sha256', 'push-retention-'.$suffix),
            'dispatch_request_id' => $dispatch->id,
            'fcm_token_id' => $token->id,
            'message_type' => 'dispatch_request',
            'title' => 'Alarm',
            'body' => 'Open de app.',
            'data' => ['type' => 'dispatch_request'],
            'available_at' => now(),
        ]);

        return [$token, $outbox];
    }

    private function revocationJob(FcmToken $token, string $generation): SendFcmNotification
    {
        return new SendFcmNotification(
            (string) $token->id,
            'session_revoked',
            'Sessie ingetrokken',
            'Je bent uitgelogd.',
            ['type' => 'session_revoked'],
            null,
            null,
            $generation,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordingProvider(
        ?Closure $duringSend = null,
        int $status = 200,
        array $payload = [],
    ): object {
        return new class($duringSend, $status, $payload) implements PushProvider
        {
            public int $sendCount = 0;

            /** @var array<string, string> */
            public array $lastData = [];

            /**
             * @param  array<string, mixed>  $payload
             */
            public function __construct(
                private readonly ?Closure $duringSend,
                private readonly int $status,
                private readonly array $payload,
            ) {}

            public function send(FcmToken $token, string $title, string $body, array $data = []): ClientResponse
            {
                $this->sendCount++;
                $this->lastData = $data;
                if ($this->duringSend !== null) {
                    ($this->duringSend)();
                }

                return new ClientResponse(new PsrResponse(
                    $this->status,
                    ['Content-Type' => 'application/json'],
                    json_encode(
                        $this->payload !== [] ? $this->payload : ['name' => 'messages/revoked'],
                        JSON_THROW_ON_ERROR,
                    ),
                ));
            }
        };
    }

    private function immediateIdentityLock(): FcmTokenIdentityLock
    {
        return new FcmTokenIdentityLock(
            FcmTokenIdentityLock::LOCK_SECONDS,
            0,
        );
    }
}
