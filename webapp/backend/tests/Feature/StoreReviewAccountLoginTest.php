<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\UpdateStoreReviewAccountRequest;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\StoreReviewAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    public function test_status_exposes_stable_platform_specific_review_setup_without_authentication_secrets(): void
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

        $accounts = collect(app(StoreReviewAccountService::class)->status()['accounts']);
        $apple = $accounts->firstWhere('platform', 'apple');
        $google = $accounts->firstWhere('platform', 'google');

        $applePayload = 'dis-ios://store-review?server=https%3A%2F%2Fmobile.example.test&client_type=operator_ios&username=apple-app-review%40system.dis.local';
        $googlePayload = 'dis://store-review?server=https%3A%2F%2Fmobile.example.test&client_type=operator_android&username=google-play-review%40system.dis.local';

        $this->assertTrue($apple['review_setup']['available']);
        $this->assertSame('https://mobile.example.test', $apple['review_setup']['server_url']);
        $this->assertSame('operator_ios', $apple['review_setup']['client_type']);
        $this->assertSame('apple-app-review@system.dis.local', $apple['review_setup']['username']);
        $this->assertSame($applePayload, $apple['review_setup']['deeplink_url']);
        $this->assertSame($applePayload, $apple['review_setup']['qr_payload']);

        $this->assertTrue($google['review_setup']['available']);
        $this->assertSame('operator_android', $google['review_setup']['client_type']);
        $this->assertSame('google-play-review@system.dis.local', $google['review_setup']['username']);
        $this->assertSame($googlePayload, $google['review_setup']['deeplink_url']);
        $this->assertSame($googlePayload, $google['review_setup']['qr_payload']);

        foreach ([$applePayload, $googlePayload] as $payload) {
            $this->assertStringNotContainsString('password', $payload);
            $this->assertStringNotContainsString('token', $payload);
            $this->assertStringNotContainsString('code=', $payload);
        }

        SystemSetting::query()->whereKey('mobile.api_base_url')->delete();
        $fallbackApple = collect(app(StoreReviewAccountService::class)->status()['accounts'])
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

        $accounts = app(StoreReviewAccountService::class)->status()['accounts'];

        foreach ($accounts as $account) {
            $this->assertTrue($account['review_setup']['available']);
            $this->assertSame('https://fallback.example.test', $account['review_setup']['server_url']);
            $this->assertStringContainsString(
                'server=https%3A%2F%2Ffallback.example.test',
                $account['review_setup']['qr_payload'],
            );
        }
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
