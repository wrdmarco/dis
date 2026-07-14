<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StoreReviewAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
            ->getJson('/api/incidents')
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
