<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\AvailabilityStatus;
use App\Models\FcmToken;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\PushNotificationService;
use App\Support\MobileApiPayload;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DeviceReachabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-25 10:00:00', 'UTC'));
    }

    public function test_doze_delayed_heartbeat_remains_reachable_without_being_online(): void
    {
        $user = $this->user('doze');
        $token = $this->token(
            $user,
            'doze-device',
            now()->subMinutes(FcmToken::onlineThresholdMinutes() + 1),
        );

        $this->assertFalse($token->is_online);
        $this->assertTrue($token->is_reachable);
        $this->assertTrue(FcmToken::query()->reachable()->whereKey($token->id)->exists());
    }

    public function test_reachability_requires_push_and_a_live_linked_operator_session(): void
    {
        $validUser = $this->user('valid');
        $valid = $this->token($validUser, 'valid-device', now());

        $expiredUser = $this->user('expired');
        $expired = $this->token(
            $expiredUser,
            'expired-device',
            now(),
            now()->subMinute(),
        );

        $unlinkedUser = $this->user('unlinked');
        $unlinked = $this->token($unlinkedUser, 'unlinked-device', now(), linkSession: false);

        $pushDisabledUser = $this->user('push-disabled', false);
        $pushDisabled = $this->token($pushDisabledUser, 'push-disabled-device', now());

        $this->assertTrue($valid->is_reachable);
        $this->assertFalse($expired->is_reachable);
        $this->assertFalse($unlinked->is_reachable);
        $this->assertFalse($pushDisabled->is_reachable);
        $this->assertEquals(
            [$valid->id],
            FcmToken::query()->reachable()->orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_reachability_rejects_mismatched_explicit_user_or_session_context(): void
    {
        $owner = $this->user('context-owner');
        $token = $this->token($owner, 'context-owner-device', now());
        $otherUser = $this->user('context-other');
        $otherSession = $otherUser->createToken(
            'Other reachability context',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;

        $this->assertFalse($token->isReachableFor($otherUser));
        $this->assertFalse($token->isReachableFor($owner, $otherSession));
    }

    public function test_reachability_expires_at_the_push_window_without_changing_online_semantics(): void
    {
        $user = $this->user('stale');
        $token = $this->token(
            $user,
            'stale-device',
            now()->subMinutes(FcmToken::pushReachabilityThresholdMinutes() + 1),
        );

        $this->assertFalse($token->is_online);
        $this->assertFalse($token->is_reachable);
        $this->assertFalse(FcmToken::query()->reachable()->whereKey($token->id)->exists());
    }

    public function test_status_payload_is_deterministic_and_uses_reachability(): void
    {
        $user = $this->user('status');
        $token = $this->token(
            $user,
            'status-device',
            now()->subMinutes(FcmToken::onlineThresholdMinutes() + 1),
        );
        $status = AvailabilityStatus::query()->create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => 'available',
            'is_available' => true,
            'is_system_applied' => false,
            'reason' => 'Handmatig beschikbaar',
            'effective_at' => now(),
        ]);

        $lazyPayload = MobileApiPayload::status(
            AvailabilityStatus::query()->findOrFail($status->id),
        );
        $partiallyEagerStatus = AvailabilityStatus::query()
            ->with('user.fcmTokens')
            ->findOrFail($status->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $eagerPayload = MobileApiPayload::status($partiallyEagerStatus);
        $nestedRelationQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $nestedRelationQueries = array_values(array_filter(
            $nestedRelationQueries,
            fn (array $query): bool => ! str_contains($query['query'], 'system_settings'),
        ));

        $this->assertSame('available', $lazyPayload['status']);
        $this->assertTrue($lazyPayload['is_available']);
        $this->assertSame('Handmatig beschikbaar', $lazyPayload['reason']);
        $this->assertFalse($lazyPayload['user']['fcm_tokens'][0]['is_online']);
        $this->assertTrue($lazyPayload['user']['fcm_tokens'][0]['is_reachable']);
        $this->assertEquals($lazyPayload, $eagerPayload);
        $this->assertCount(
            1,
            $nestedRelationQueries,
            json_encode(array_column($nestedRelationQueries, 'query'), JSON_THROW_ON_ERROR),
        );
        $this->assertStringContainsString(
            'personal_access_tokens',
            $nestedRelationQueries[0]['query'],
        );

        $token->personalAccessToken()->update(['expires_at' => now()->subMinute()]);
        $unreachablePayload = MobileApiPayload::status(
            AvailabilityStatus::query()->findOrFail($status->id),
        );

        $this->assertSame('unavailable', $unreachablePayload['status']);
        $this->assertFalse($unreachablePayload['is_available']);
        $this->assertSame(
            'Niet bereikbaar: geen bereikbaar operator-device.',
            $unreachablePayload['reason'],
        );
        $this->assertFalse($unreachablePayload['user']['fcm_tokens'][0]['is_reachable']);
    }

    public function test_eager_loaded_reachability_serialization_does_not_query_per_device(): void
    {
        $user = $this->user('eager-loading');
        $this->token($user, 'eager-device-one', now());
        $this->token($user, 'eager-device-two', now()->subHour());

        $loaded = User::query()
            ->with(['roles.permissions', 'teams', 'fcmTokens.personalAccessToken'])
            ->findOrFail($user->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $payload = MobileApiPayload::user($loaded);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $queries = array_values(array_filter(
            $queries,
            fn (array $query): bool => ! str_contains($query['query'], 'system_settings'),
        ));

        $this->assertCount(
            0,
            $queries,
            json_encode(array_column($queries, 'query'), JSON_THROW_ON_ERROR),
        );
        $this->assertCount(2, $payload['fcm_tokens']);
        $this->assertTrue($payload['fcm_tokens'][0]['is_reachable']);
        $this->assertTrue($payload['fcm_tokens'][1]['is_reachable']);
        $this->assertNull($payload['fcm_tokens'][0]['user']);
    }

    public function test_manual_push_uses_the_same_reachability_contract(): void
    {
        Queue::fake();
        $actor = $this->user('manual-actor');
        $reachable = $this->user('manual-reachable');
        $reachableToken = $this->token(
            $reachable,
            'manual-reachable-device',
            now()->subMinutes(FcmToken::onlineThresholdMinutes() + 1),
        );
        $expired = $this->user('manual-expired');
        $expiredToken = $this->token(
            $expired,
            'manual-expired-device',
            now(),
            now()->subMinute(),
        );

        $result = app(PushNotificationService::class)->sendManual($actor, [
            'title' => 'Bereikbaarheidstest',
            'body' => 'Alleen het bereikbare toestel ontvangt dit bericht.',
            'team_ids' => [],
            'role_ids' => [],
            'user_ids' => [$reachable->id, $expired->id],
        ]);

        $this->assertSame(['queued_tokens' => 1, 'recipient_users' => 1], $result);
        Queue::assertPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->fcmTokenId === $reachableToken->id,
        );
        Queue::assertNotPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->fcmTokenId === $expiredToken->id,
        );
    }

    private function user(string $suffix, bool $pushEnabled = true): User
    {
        return User::query()->create([
            'name' => 'Reachability '.$suffix,
            'first_name' => 'Reachability',
            'last_name' => $suffix,
            'email' => "reachability-{$suffix}@example.test",
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => $pushEnabled,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function token(
        User $user,
        string $deviceId,
        mixed $lastSeenAt,
        mixed $sessionExpiresAt = null,
        bool $linkSession = true,
    ): FcmToken {
        $session = $linkSession
            ? $user->createToken(
                'Reachability '.$deviceId,
                ['*', 'client:operator'],
                $sessionExpiresAt ?? now()->addHour(),
            )->accessToken
            : null;
        $providerToken = 'provider-'.$deviceId;

        return FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'personal_access_token_id' => $session instanceof PersonalAccessToken ? $session->id : null,
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => $lastSeenAt,
        ]);
    }
}
