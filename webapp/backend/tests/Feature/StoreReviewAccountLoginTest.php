<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\UpdateStoreReviewAccountRequest;
use App\Models\AuditLog;
use App\Models\MobilePairingCode;
use App\Models\PersonalAccessToken;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\StoreReviewAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class StoreReviewAccountLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_reviewer_can_only_login_to_android_without_mfa(): void
    {
        $this->configureAccount('google', 'Google-review-password-123!');

        $login = $this->postJson('/api/auth/login', [
            'email' => 'google-play-review@system.dis.local',
            'password' => 'Google-review-password-123!',
            'device_name' => 'Google Play Review',
            'client_type' => 'operator_android',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.requires_2fa', false)
            ->assertJsonPath('data.user.account_status', 'store_review')
            ->assertJsonStructure(['data' => ['token']]);

        $this->withToken((string) $login->json('data.token'))
            ->getJson('/api/deployments')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'REVIEW-0001')
            ->assertJsonPath('data.0.is_test', true);

        $this->postJson('/api/auth/login', [
            'email' => 'google-play-review@system.dis.local',
            'password' => 'Google-review-password-123!',
            'device_name' => 'iPhone',
            'client_type' => 'operator_ios',
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');

        $account = collect(app(StoreReviewAccountService::class)->status()['accounts'])
            ->firstWhere('platform', 'google');
        $this->assertCount(2, $account['recent_login_events']);
        $this->assertSame('blocked', $account['recent_login_events'][0]['result']);
        $this->assertSame('operator_ios', $account['recent_login_events'][0]['client_type']);
        $this->assertSame('success', $account['recent_login_events'][1]['result']);
        $this->assertSame('Google Play Review', $account['recent_login_events'][1]['device_name']);
    }

    public function test_apple_reviewer_can_only_login_to_ios_and_never_to_web(): void
    {
        $this->configureAccount('apple', 'Apple-review-password-123!');

        $this->postJson('/api/auth/login', [
            'email' => 'apple-app-review@system.dis.local',
            'password' => 'Apple-review-password-123!',
            'device_name' => 'App Store Review',
            'client_type' => 'operator_ios',
        ])->assertOk()->assertJsonPath('data.requires_2fa', false);

        $this->postJson('/api/auth/login', [
            'email' => 'apple-app-review@system.dis.local',
            'password' => 'Apple-review-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertForbidden()->assertJsonMissingPath('data.token');
    }

    public function test_disabling_a_reviewer_account_revokes_access(): void
    {
        [$service, $actor] = $this->serviceAndActor();
        $service->configure('google', true, 'Google-review-password-123!', $actor, Request::create('/admin', 'PATCH'));
        $service->configure('google', false, null, $actor, Request::create('/admin', 'PATCH'));

        $this->postJson('/api/auth/login', [
            'email' => 'google-play-review@system.dis.local',
            'password' => 'Google-review-password-123!',
            'device_name' => 'Google Play Review',
            'client_type' => 'operator_android',
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
    }

    public function test_reviewer_vacation_payload_supports_availability_and_updates_without_writes(): void
    {
        $this->configureAccount('google', 'Google-review-password-123!');
        $login = $this->postJson('/api/auth/login', [
            'email' => 'google-play-review@system.dis.local',
            'password' => 'Google-review-password-123!',
            'device_name' => 'Google Play Review',
            'client_type' => 'operator_android',
        ])->assertOk();
        $token = (string) $login->json('data.token');

        $this->withToken($token)
            ->postJson('/api/vacations/mine', [
                'starts_at' => today()->toDateString(),
                'ends_at' => today()->addDay()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.user', null);

        $this->withToken($token)
            ->patchJson('/api/vacations/store-review-vacation', [
                'starts_at' => today()->addDay()->toDateString(),
                'ends_at' => today()->addDays(2)->toDateString(),
                'is_available' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', 'store-review-vacation')
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertDatabaseCount('availability_overrides', 0);
    }

    public function test_reviewer_password_requires_all_character_groups_and_24_characters(): void
    {
        $rules = (new UpdateStoreReviewAccountRequest)->rules();

        $this->assertTrue(Validator::make([
            'enabled' => true,
            'password' => 'only-lowercase-and-long-enough',
        ], $rules)->fails());

        $this->assertFalse(Validator::make([
            'enabled' => true,
            'password' => 'Store-Review-Password-2468!',
        ], $rules)->fails());
    }

    public function test_status_exposes_stable_non_expiring_pairing_codes_without_passwords_or_tokens(): void
    {
        SystemSetting::query()->create([
            'key' => 'app.public_url',
            'value' => 'https://fallback.example.test',
            'is_sensitive' => false,
        ]);
        SystemSetting::query()->create([
            'key' => 'mobile.api_base_url',
            'value' => 'https://mobile.example.test/api/',
            'is_sensitive' => false,
        ]);

        [$service, $actor] = $this->serviceAndActor();
        $service->configure('apple', true, 'Apple-review-password-123!', $actor, Request::create('/admin', 'PATCH'));
        $service->configure('google', true, 'Google-review-password-123!', $actor, Request::create('/admin', 'PATCH'));

        $accounts = collect($service->status()['accounts']);
        $apple = $accounts->firstWhere('platform', 'apple');
        $google = $accounts->firstWhere('platform', 'google');

        $applePayload = 'dis://pair?server=https%3A%2F%2Fmobile.example.test&code='.
            $apple['review_setup']['code'].'&client_type=operator_ios';
        $googlePayload = 'dis://pair?server=https%3A%2F%2Fmobile.example.test&code='.
            $google['review_setup']['code'].'&client_type=operator_android';

        $this->assertTrue($apple['review_setup']['available']);
        $this->assertSame('https://mobile.example.test', $apple['review_setup']['server_url']);
        $this->assertSame('operator_ios', $apple['review_setup']['client_type']);
        $this->assertSame('apple-app-review@system.dis.local', $apple['review_setup']['username']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $apple['review_setup']['code']);
        $this->assertNull($apple['review_setup']['expires_at']);
        $this->assertNotNull($apple['review_setup']['issued_at']);
        $this->assertSame($applePayload, $apple['review_setup']['deeplink_url']);
        $this->assertSame($applePayload, $apple['review_setup']['qr_payload']);

        $this->assertTrue($google['review_setup']['available']);
        $this->assertSame('operator_android', $google['review_setup']['client_type']);
        $this->assertSame('google-play-review@system.dis.local', $google['review_setup']['username']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $google['review_setup']['code']);
        $this->assertNull($google['review_setup']['expires_at']);
        $this->assertSame($googlePayload, $google['review_setup']['deeplink_url']);
        $this->assertSame($googlePayload, $google['review_setup']['qr_payload']);

        foreach ([$applePayload, $googlePayload] as $payload) {
            $this->assertStringNotContainsString('password', $payload);
            $this->assertStringNotContainsString('token', $payload);
            $this->assertStringNotContainsString('username', $payload);
        }

        $stable = collect($service->status()['accounts']);
        $this->assertSame($applePayload, $stable->firstWhere('platform', 'apple')['review_setup']['qr_payload']);
        $this->assertSame($googlePayload, $stable->firstWhere('platform', 'google')['review_setup']['qr_payload']);

        SystemSetting::query()->whereKey('mobile.api_base_url')->delete();
        $fallbackApple = collect($service->status()['accounts'])
            ->firstWhere('platform', 'apple');

        $this->assertSame('https://fallback.example.test', $fallbackApple['review_setup']['server_url']);
        $this->assertStringContainsString(
            'server=https%3A%2F%2Ffallback.example.test',
            $fallbackApple['review_setup']['qr_payload'],
        );
    }

    public function test_status_explains_when_review_setup_has_no_public_https_server(): void
    {
        foreach ([
            null,
            'http://mobile.example.test/api',
            'https://mobile.example.test/subpath/api',
            'https://mobile.example.test/api?tenant=review',
            'https://reviewer@mobile.example.test/api',
            'https://mobile.example.test/api#review',
        ] as $configuredUrl) {
            SystemSetting::query()->whereKey('mobile.api_base_url')->delete();
            if ($configuredUrl !== null) {
                SystemSetting::query()->create([
                    'key' => 'mobile.api_base_url',
                    'value' => $configuredUrl,
                    'is_sensitive' => false,
                ]);
            }

            $accounts = app(StoreReviewAccountService::class)->status()['accounts'];

            foreach ($accounts as $account) {
                $this->assertFalse($account['review_setup']['available']);
                $this->assertNull($account['review_setup']['server_url']);
                $this->assertNull($account['review_setup']['code']);
                $this->assertNull($account['review_setup']['expires_at']);
                $this->assertNull($account['review_setup']['issued_at']);
                $this->assertNull($account['review_setup']['deeplink_url']);
                $this->assertNull($account['review_setup']['qr_payload']);
                $this->assertSame(
                    'Stel eerst een geldige publieke HTTPS-URL in bij Beheer > Push.',
                    $account['review_setup']['configuration_error'],
                );
            }
        }
    }

    public function test_status_falls_back_when_mobile_server_is_not_a_bare_https_origin(): void
    {
        SystemSetting::query()->create([
            'key' => 'mobile.api_base_url',
            'value' => 'https://mobile.example.test/subpath/api',
            'is_sensitive' => false,
        ]);
        SystemSetting::query()->create([
            'key' => 'app.public_url',
            'value' => 'https://fallback.example.test/',
            'is_sensitive' => false,
        ]);

        [$service, $actor] = $this->serviceAndActor();
        $service->configure('apple', true, 'Apple-review-password-123!', $actor, Request::create('/admin', 'PATCH'));
        $service->configure('google', true, 'Google-review-password-123!', $actor, Request::create('/admin', 'PATCH'));

        $accounts = $service->status()['accounts'];

        foreach ($accounts as $account) {
            $this->assertTrue($account['review_setup']['available']);
            $this->assertSame('https://fallback.example.test', $account['review_setup']['server_url']);
            $this->assertStringContainsString(
                'server=https%3A%2F%2Ffallback.example.test',
                $account['review_setup']['qr_payload'],
            );
        }
    }

    public function test_review_pairing_code_never_expires_and_rotates_atomically_after_consumption(): void
    {
        SystemSetting::query()->create([
            'key' => 'mobile.api_base_url',
            'value' => 'https://mobile.example.test/api',
            'is_sensitive' => false,
        ]);
        [$service, $actor] = $this->serviceAndActor();
        $configured = $service->configure(
            'google',
            true,
            'Google-review-password-123!',
            $actor,
            Request::create('/admin/store-review/accounts/google', 'PATCH'),
        );
        $firstSetup = $configured['review_setup'];
        $firstCode = (string) $firstSetup['code'];

        $firstPairing = MobilePairingCode::query()->where('active_review_slot', 'google')->firstOrFail();
        $this->assertNull($firstPairing->expires_at);
        $this->assertSame($firstCode, $firstPairing->review_code);
        $this->assertNotSame($firstCode, DB::table('mobile_pairing_codes')->where('id', $firstPairing->id)->value('review_code'));

        $this->travel(365)->days();
        $consume = $this->postJson('/api/auth/mobile-pairing/consume', [
            'code' => $firstCode,
            'client_type' => 'operator_android',
            'device_name' => 'Google Play Review',
        ])->assertOk()
            ->assertJsonPath('data.client_type', 'operator_android')
            ->assertJsonPath('data.user.account_status', 'store_review');

        $plainToken = (string) $consume->json('data.token');
        $tokenId = str($plainToken)->before('|')->toString();
        $token = PersonalAccessToken::query()->findOrFail($tokenId);
        $this->assertSame(['client:store_review'], $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addHours(23), now()->addHours(25)));

        $firstPairing->refresh();
        $this->assertNotNull($firstPairing->consumed_at);
        $this->assertNull($firstPairing->review_code);
        $this->assertNull($firstPairing->active_review_slot);

        $secondSetup = collect($service->status()['accounts'])
            ->firstWhere('platform', 'google')['review_setup'];
        $this->assertNotSame($firstCode, $secondSetup['code']);
        $this->assertNotSame($firstSetup['qr_payload'], $secondSetup['qr_payload']);
        $this->assertNull($secondSetup['expires_at']);

        $this->postJson('/api/auth/mobile-pairing/consume', [
            'code' => $firstCode,
            'client_type' => 'operator_android',
            'device_name' => 'Replay attempt',
        ])->assertStatus(422);

        $blockedReplay = AuditLog::query()
            ->where('action', 'auth.store_review_pairing_blocked')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame((string) $firstPairing->id, (string) $blockedReplay->target_id);
        $this->assertSame('replayed_or_revoked', $blockedReplay->metadata['reason_code']);
        $this->assertArrayNotHasKey('code', (array) $blockedReplay->metadata);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.store_review_pairing_consumed',
            'target_id' => (string) $firstPairing->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.store_review_pairing_rotated',
        ]);
    }

    public function test_wrong_platform_does_not_consume_or_rotate_review_pairing_code(): void
    {
        SystemSetting::query()->create([
            'key' => 'app.public_url',
            'value' => 'https://mobile.example.test',
            'is_sensitive' => false,
        ]);
        [$service, $actor] = $this->serviceAndActor();
        $configured = $service->configure(
            'apple',
            true,
            'Apple-review-password-123!',
            $actor,
            Request::create('/admin/store-review/accounts/apple', 'PATCH'),
        );
        $code = (string) $configured['review_setup']['code'];

        $this->postJson('/api/auth/mobile-pairing/consume', [
            'code' => $code,
            'client_type' => 'operator_android',
            'device_name' => 'Wrong platform',
        ])->assertStatus(422);

        $setup = collect($service->status()['accounts'])->firstWhere('platform', 'apple')['review_setup'];
        $this->assertSame($code, $setup['code']);
        $this->assertNull(MobilePairingCode::query()->where('active_review_slot', 'apple')->firstOrFail()->consumed_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $blockedAttempt = AuditLog::query()
            ->where('action', 'auth.store_review_pairing_blocked')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('client_or_state_mismatch', $blockedAttempt->metadata['reason_code']);
        $this->assertSame('operator_android', $blockedAttempt->metadata['client_type']);
        $this->assertArrayNotHasKey('code', (array) $blockedAttempt->metadata);
    }

    public function test_password_change_rotates_review_pairing_and_disabling_removes_it(): void
    {
        SystemSetting::query()->create([
            'key' => 'app.public_url',
            'value' => 'https://mobile.example.test',
            'is_sensitive' => false,
        ]);
        [$service, $actor] = $this->serviceAndActor();
        $first = $service->configure(
            'google',
            true,
            'Google-review-password-123!',
            $actor,
            Request::create('/admin/store-review/accounts/google', 'PATCH'),
        );
        $second = $service->configure(
            'google',
            true,
            'Changed-review-password-456!',
            $actor,
            Request::create('/admin/store-review/accounts/google', 'PATCH'),
        );

        $this->assertNotSame($first['review_setup']['code'], $second['review_setup']['code']);
        $this->postJson('/api/auth/mobile-pairing/consume', [
            'code' => $first['review_setup']['code'],
            'client_type' => 'operator_android',
        ])->assertStatus(422);

        $disabled = $service->configure(
            'google',
            false,
            null,
            $actor,
            Request::create('/admin/store-review/accounts/google', 'PATCH'),
        );
        $this->assertFalse($disabled['review_setup']['available']);
        $this->assertNull($disabled['review_setup']['code']);
        $this->assertNull($disabled['review_setup']['qr_payload']);
        $this->assertDatabaseMissing('mobile_pairing_codes', ['active_review_slot' => 'google']);
    }

    public function test_status_lazily_issues_and_audits_code_for_preexisting_enabled_review_account(): void
    {
        SystemSetting::query()->create([
            'key' => 'app.public_url',
            'value' => 'https://mobile.example.test',
            'is_sensitive' => false,
        ]);
        [$service, $actor] = $this->serviceAndActor();
        $reviewer = User::query()->create([
            'name' => 'Apple App Review',
            'email' => 'apple-app-review@system.dis.local',
            'password' => 'Apple-review-password-123!',
            'account_status' => 'store_review',
        ]);
        $request = Request::create('/api/admin/store-review/status', 'GET');
        $request->setUserResolver(static fn (): User => $actor);

        $setup = collect($service->status($request)['accounts'])
            ->firstWhere('platform', 'apple')['review_setup'];

        $this->assertTrue($setup['available']);
        $this->assertNotNull($setup['code']);
        $pairing = MobilePairingCode::query()->where('user_id', $reviewer->id)->firstOrFail();
        $audit = AuditLog::query()->where('action', 'auth.store_review_pairing_created')->latest('id')->firstOrFail();
        $this->assertSame((string) $actor->id, (string) $audit->actor_id);
        $this->assertSame((string) $pairing->id, (string) $audit->target_id);
        $this->assertArrayNotHasKey('code', (array) $audit->metadata);
    }

    private function configureAccount(string $platform, string $password): void
    {
        [$service, $actor] = $this->serviceAndActor();
        $service->configure($platform, true, $password, $actor, Request::create('/admin', 'PATCH'));
    }

    /** @return array{StoreReviewAccountService, User} */
    private function serviceAndActor(): array
    {
        $actor = User::query()->create([
            'name' => 'System administrator',
            'email' => 'admin@example.test',
            'password' => Hash::make('Admin-password-123!'),
            'account_status' => 'active',
        ]);

        return [app(StoreReviewAccountService::class), $actor];
    }
}
