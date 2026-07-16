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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PushProviderCoalescingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_alarm_uses_the_same_safe_collapse_id_for_fcm_and_apns(): void
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
        $data = ['type' => 'dispatch_request', 'dispatch_id' => $dispatchId];

        app(FcmClient::class)->send($androidToken, 'Alarm', 'Open de app.', $data);
        app(ApnsClient::class)->send($iosToken, 'Alarm', 'Open de app.', $data);

        Http::assertSent(static function (ClientRequest $request) use ($collapseId): bool {
            $payload = $request->data();

            return str_contains($request->url(), 'fcm.googleapis.com')
                && ($payload['message']['android']['collapse_key'] ?? null) === $collapseId;
        });
        Http::assertSent(static fn (ClientRequest $request): bool => str_contains($request->url(), 'api.push.apple.com')
            && $request->hasHeader('apns-collapse-id', $collapseId));
        $this->assertNull(PushNotificationIdentity::dispatchCollapseId([
            'type' => 'dispatch_update',
            'dispatch_id' => $dispatchId,
        ]));
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
