<?php

namespace Tests\Feature;

use App\Models\FcmToken;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Apple\ApnsClient;
use App\Services\Firebase\FcmClient;
use App\Support\PushNotificationIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PushProviderCoalescingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_alarm_is_non_collapsible_on_fcm_and_uses_the_stable_apns_identifier(): void
    {
        $dispatchId = (string) Str::ulid();
        $collapseId = 'dispatch-'.$dispatchId;
        $user = User::query()->create([
            'name' => 'Provider Coalescing Pilot',
            'first_name' => 'Provider',
            'last_name' => 'Pilot',
            'email' => 'provider-coalescing@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
        ]);
        $androidToken = $this->token($user, 'android', 'android-coalescing-device');
        $iosToken = $this->token($user, 'ios', 'ios-coalescing-device');
        SystemSetting::query()->updateOrCreate(
            ['key' => 'firebase.project_id'],
            ['value' => 'test-project', 'is_sensitive' => false],
        );
        SystemSetting::query()->updateOrCreate(
            ['key' => 'push.apns.credentials'],
            ['value' => [
                'team_id' => 'test-team',
                'key_id' => 'test-key',
                'bundle_id' => 'nl.example.dis.operator',
                'private_key' => 'test-only-unused-key',
                'environment' => 'production',
            ], 'is_sensitive' => true],
        );
        Cache::put('firebase.messaging.access_token', 'test-fcm-access-token', now()->addHour());
        Cache::put(
            'apns.provider_token.'.hash('sha256', 'test-keytest-team'),
            'test-apns-provider-token',
            now()->addHour(),
        );
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'messages/test'], 200),
            'https://api.push.apple.com/*' => Http::response([], 200, ['apns-id' => 'test-apns-id']),
        ]);
        $data = [
            'type' => 'dispatch_request',
            'action_mode' => 'attendance',
            'dispatch_id' => $dispatchId,
        ];

        app(FcmClient::class)->send($androidToken, 'Alarm', 'Open de app.', $data);
        app(ApnsClient::class)->send($iosToken, 'Alarm', 'Open de app.', $data);

        Http::assertSent(static function (ClientRequest $request): bool {
            $payload = $request->data();

            return str_contains($request->url(), 'fcm.googleapis.com')
                && ! array_key_exists('collapse_key', $payload['message']['android'] ?? []);
        });
        Http::assertSent(static function (ClientRequest $request) use ($collapseId): bool {
            $payload = $request->data();

            return str_contains($request->url(), 'api.push.apple.com')
                && $request->hasHeader('apns-collapse-id', $collapseId)
                && ($payload['aps']['alert']['title'] ?? null) === 'Nieuwe D.I.S.-melding'
                && ($payload['aps']['alert']['body'] ?? null) === 'Open D.I.S. om de actuele melding veilig te bekijken.'
                && ($payload['aps']['alert']['title'] ?? null) !== ($payload['title'] ?? null)
                && ($payload['aps']['alert']['body'] ?? null) !== ($payload['body'] ?? null);
        });
        $this->assertNull(PushNotificationIdentity::dispatchCollapseId([
            'type' => 'dispatch_update',
            'dispatch_id' => $dispatchId,
        ]));
    }

    public function test_apns_dispatch_phases_share_one_safe_provider_collapse_id(): void
    {
        $dispatchId = (string) Str::ulid();
        $collapseId = 'dispatch-'.$dispatchId;
        $phases = [
            ['type' => 'dispatch_update', 'action_mode' => 'availability'],
            ['type' => 'incident_preannouncement', 'action_mode' => 'availability'],
            ['type' => 'dispatch_request', 'action_mode' => 'attendance'],
            ['type' => 'dispatch_update', 'action_mode' => 'attendance'],
            ['type' => 'dispatch_response_sync', 'action_mode' => 'availability'],
            ['type' => 'dispatch_response_sync', 'action_mode' => 'attendance'],
            ['type' => 'dispatch_response_sync', 'action_mode' => 'test_ack'],
        ];

        $this->assertLessThanOrEqual(64, strlen($collapseId));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/i', $collapseId);
        foreach ($phases as $phase) {
            $this->assertSame($collapseId, PushNotificationIdentity::dispatchCollapseId([
                ...$phase,
                'dispatch_id' => $dispatchId,
            ]));
        }

        $this->assertNull(PushNotificationIdentity::dispatchCollapseId([
            'type' => 'dispatch_update',
            'action_mode' => 'additional_info',
            'dispatch_id' => $dispatchId,
        ]));
        $this->assertNull(PushNotificationIdentity::dispatchCollapseId([
            'type' => 'dispatch_response_sync',
            'action_mode' => 'unknown',
            'dispatch_id' => $dispatchId,
        ]));
    }

    public function test_provider_submission_lock_is_shared_by_all_phases_for_one_incident_and_device(): void
    {
        $incidentId = (string) Str::ulid();
        $dispatchId = (string) Str::ulid();
        $tokenId = (string) Str::ulid();
        $otherTokenId = (string) Str::ulid();
        $preannouncement = [
            'type' => 'dispatch_update',
            'action_mode' => 'availability',
            'incident_id' => $incidentId,
            'dispatch_id' => $dispatchId,
        ];
        $alarm = [
            'type' => 'dispatch_request',
            'action_mode' => 'attendance',
            'incident_id' => $incidentId,
            'dispatch_id' => $dispatchId,
        ];

        $preannouncementKey = PushNotificationIdentity::deliveryOrderLockKey($preannouncement, $tokenId, $dispatchId);
        $alarmKey = PushNotificationIdentity::deliveryOrderLockKey($alarm, $tokenId, $dispatchId);

        $this->assertNotNull($preannouncementKey);
        $this->assertSame($preannouncementKey, $alarmKey);
        $this->assertNotSame(
            $preannouncementKey,
            PushNotificationIdentity::deliveryOrderLockKey($alarm, $otherTokenId, $dispatchId),
        );
        $this->assertStringNotContainsString($incidentId, $preannouncementKey);
        $this->assertStringNotContainsString($tokenId, $preannouncementKey);
    }

    public function test_legacy_preannouncement_has_a_bounded_lifetime_on_fcm_and_apns(): void
    {
        Carbon::setTestNow('2026-07-17T10:00:00Z');
        try {
            $user = User::query()->create([
                'name' => 'Preannouncement TTL Pilot',
                'first_name' => 'TTL',
                'last_name' => 'Pilot',
                'email' => 'preannouncement-ttl@example.test',
                'password' => Hash::make('Test-password-123!'),
                'account_status' => 'active',
            ]);
            $androidToken = $this->token($user, 'android', 'android-preannouncement-ttl');
            $iosToken = $this->token($user, 'ios', 'ios-preannouncement-ttl');
            $this->configureFcm();
            $this->configureApns();
            Http::fake([
                'https://fcm.googleapis.com/*' => Http::response(['name' => 'messages/test'], 200),
                'https://api.push.apple.com/*' => Http::response([], 200, ['apns-id' => 'test-apns-id']),
            ]);
            $dispatchId = (string) Str::ulid();
            $data = [
                'type' => 'dispatch_update',
                'action_mode' => 'availability',
                'dispatch_id' => $dispatchId,
            ];

            app(FcmClient::class)->send($androidToken, 'Vooraankondiging', 'Ben je beschikbaar?', $data);
            app(ApnsClient::class)->send($iosToken, 'Vooraankondiging', 'Ben je beschikbaar?', $data);

            Http::assertSent(static function (ClientRequest $request): bool {
                if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                    return false;
                }
                $android = $request->data()['message']['android'] ?? [];

                return ($android['ttl'] ?? null) === '120s'
                    && ! array_key_exists('collapse_key', $android);
            });
            Http::assertSent(static fn (ClientRequest $request): bool => str_contains($request->url(), 'api.push.apple.com')
                && $request->hasHeader('apns-expiration', '1784282520')
                && $request->hasHeader('apns-collapse-id', 'dispatch-'.$dispatchId));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_visible_operational_messages_use_high_android_priority_and_remain_data_only(): void
    {
        $token = $this->androidToken('visible-priority');
        $this->configureFcm();

        $this->sendAndAssertAndroidPriorities($token, [
            'dispatch_request' => 'HIGH',
            'dispatch_update' => 'HIGH',
            'incident_preannouncement' => 'HIGH',
            'manual_admin' => 'HIGH',
            'location_share_request' => 'HIGH',
            'incident_cancelled' => 'HIGH',
        ]);
    }

    public function test_silent_control_and_unknown_messages_use_normal_android_priority(): void
    {
        $token = $this->androidToken('silent-priority');
        $this->configureFcm();

        $this->sendAndAssertAndroidPriorities($token, [
            'device_presence_ping' => 'NORMAL',
            'dispatch_response_sync' => 'NORMAL',
            'location_sharing_stopped' => 'NORMAL',
            'session_revoked' => 'NORMAL',
            'unknown_control_message' => 'NORMAL',
        ]);
    }

    private function androidToken(string $suffix): FcmToken
    {
        $user = User::query()->create([
            'name' => 'Priority Pilot '.$suffix,
            'first_name' => 'Priority',
            'last_name' => 'Pilot',
            'email' => 'priority-'.$suffix.'@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
        ]);

        return $this->token($user, 'android', 'android-'.$suffix.'-device');
    }

    private function configureFcm(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'firebase.project_id'],
            ['value' => 'test-project', 'is_sensitive' => false],
        );
        Cache::put('firebase.messaging.access_token', 'test-fcm-access-token', now()->addHour());
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response(['name' => 'messages/test'], 200),
        ]);
    }

    private function configureApns(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'push.apns.credentials'],
            ['value' => [
                'team_id' => 'test-team',
                'key_id' => 'test-key',
                'bundle_id' => 'nl.example.dis.operator',
                'private_key' => 'test-only-unused-key',
                'environment' => 'production',
            ], 'is_sensitive' => true],
        );
        Cache::put(
            'apns.provider_token.'.hash('sha256', 'test-keytest-team'),
            'test-apns-provider-token',
            now()->addHour(),
        );
    }

    /**
     * @param  array<string, string>  $expectedPriorities
     */
    private function sendAndAssertAndroidPriorities(FcmToken $token, array $expectedPriorities): void
    {
        foreach ($expectedPriorities as $type => $priority) {
            app(FcmClient::class)->send($token, 'Titel', 'Bericht', ['type' => $type]);
        }

        $requests = Http::recorded(
            static fn (ClientRequest $request): bool => str_contains($request->url(), 'fcm.googleapis.com'),
        );
        $this->assertCount(count($expectedPriorities), $requests);

        foreach ($requests as [$request]) {
            $message = $request->data()['message'];
            $type = $message['data']['type'];

            $this->assertArrayHasKey($type, $expectedPriorities);
            $this->assertSame($expectedPriorities[$type], $message['android']['priority'] ?? null);
            $this->assertArrayNotHasKey('notification', $message);
            $this->assertArrayNotHasKey('notification', $message['android']);
            if ($type === 'incident_preannouncement') {
                $this->assertSame('120s', $message['android']['ttl'] ?? null);
            } else {
                $this->assertArrayNotHasKey('ttl', $message['android']);
            }
        }
    }

    private function token(User $user, string $platform, string $deviceId): FcmToken
    {
        return FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'token' => $deviceId.'-token',
            'token_hash' => hash('sha256', $deviceId.'-token'),
            'platform' => $platform,
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    }
}
