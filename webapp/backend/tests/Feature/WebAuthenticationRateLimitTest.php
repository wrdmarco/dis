<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WebAuthenticationRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_login_has_an_ip_limit_even_when_the_attacker_rotates_account_names(): void
    {
        $limited = null;
        for ($attempt = 1; $attempt <= 40; $attempt++) {
            $response = $this->login(
                email: "rotated-account-{$attempt}@example.test",
                password: 'invalid-password',
                ip: '198.51.100.10',
            );
            if ($response->getStatusCode() === 429) {
                $limited = $response;
                break;
            }
        }

        $this->assertNotNull($limited, 'Login must have a reasonable per-IP limit independent of the submitted account.');
        $this->assertRateLimited($limited);
    }

    public function test_login_has_an_account_limit_even_when_the_attacker_rotates_ip_addresses(): void
    {
        $limited = null;
        for ($attempt = 1; $attempt <= 40; $attempt++) {
            $response = $this->login(
                email: 'target-account@example.test',
                password: 'invalid-password',
                ip: '198.51.100.'.($attempt + 20),
            );
            if ($response->getStatusCode() === 429) {
                $limited = $response;
                break;
            }
        }

        $this->assertNotNull($limited, 'Login must have a reasonable per-account limit independent of the source IP.');
        $this->assertRateLimited($limited);
    }

    public function test_login_rate_limit_recovers_after_the_decay_window(): void
    {
        $limited = null;
        for ($attempt = 1; $attempt <= 40; $attempt++) {
            $response = $this->login('decay@example.test', 'invalid-password', '203.0.113.20');
            if ($response->getStatusCode() === 429) {
                $limited = $response;
                break;
            }
        }
        $this->assertNotNull($limited);

        // The controller keeps the account-scoped failure window for five minutes,
        // while the route-level IP limiter decays after one minute.
        $this->travel(6)->minutes();

        $this->assertNotSame(
            429,
            $this->login('decay@example.test', 'invalid-password', '203.0.113.20')->getStatusCode(),
            'A temporary rate limit must recover after its configured window.',
        );
    }

    public function test_invalid_credentials_do_not_reveal_whether_an_account_exists(): void
    {
        User::query()->create([
            'name' => 'Existing Security User',
            'first_name' => 'Existing',
            'last_name' => 'Security User',
            'email' => 'existing@example.test',
            'password' => Hash::make('Correct-password-123!'),
            'account_status' => 'active',
        ]);

        $existing = $this->login('existing@example.test', 'wrong-password', '192.0.2.10');
        $missing = $this->login('missing@example.test', 'wrong-password', '192.0.2.11');

        $this->assertSame($existing->getStatusCode(), $missing->getStatusCode());
        $this->assertSame($existing->json('error.code'), $missing->json('error.code'));
        $this->assertSame($existing->json('error.message'), $missing->json('error.message'));
        $this->assertStringNotContainsString('existing@example.test', $existing->getContent());
        $this->assertStringNotContainsString('missing@example.test', $missing->getContent());
    }

    public function test_correct_password_recovers_from_a_failure_lock_without_admin_intervention(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => TwoFactorService::REQUIRED_KEY],
            ['value' => false, 'is_sensitive' => false],
        );
        $user = User::query()->create([
            'name' => 'Recoverable Lock User',
            'first_name' => 'Recoverable',
            'last_name' => 'Lock User',
            'email' => 'recoverable-lock@example.test',
            'password' => Hash::make('Correct-password-123!'),
            'account_status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => 'recoverable-lock-operator',
            'display_name' => 'Recoverable lock operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login($user->email, 'wrong-password', '198.51.100.'.($attempt + 100))
                ->assertUnprocessable();
        }
        $this->assertTrue($user->refresh()->login_locked_until?->isFuture() ?? false);

        $this->login($user->email, 'Correct-password-123!', '198.51.100.200')
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
        $this->assertSame(0, (int) $user->refresh()->failed_login_attempts);
        $this->assertNull($user->login_locked_until);
    }

    public function test_authenticated_api_limiter_returns_one_audited_429_response(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => TwoFactorService::REQUIRED_KEY],
            ['value' => false, 'is_sensitive' => false],
        );
        $user = User::query()->create([
            'name' => 'Authenticated Rate Limit User',
            'first_name' => 'Authenticated',
            'last_name' => 'Rate Limit User',
            'email' => 'authenticated-rate-limit@example.test',
            'password' => Hash::make('Correct-password-123!'),
            'account_status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => 'authenticated-rate-limit-operator',
            'display_name' => 'Authenticated rate-limit operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);
        $token = $user->createToken('DIS Operator Android', ['*', 'client:operator'])->plainTextToken;

        RateLimiter::for('authenticated', fn (Request $request) => Limit::perMinute(1)
            ->by('authenticated-contract:'.$request->user()?->getAuthIdentifier()));

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertHeader('Retry-After');

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'security.rate_limit_exceeded',
        ]);
    }

    public function test_two_factor_verification_is_limited_and_returns_retry_after(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => TwoFactorService::REQUIRED_KEY],
            ['value' => true, 'is_sensitive' => false],
        );
        $user = User::query()->create([
            'name' => 'MFA Rate Limit User',
            'first_name' => 'MFA',
            'last_name' => 'Rate Limit User',
            'email' => 'mfa-rate-limit@example.test',
            'password' => Hash::make('Correct-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['VALID-RECOVERY'],
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'mfa-rate-limit-operator',
            'display_name' => 'MFA rate-limit operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        $login = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'Correct-password-123!',
                'device_name' => 'DIS Android security test',
                'client_type' => 'operator_android',
            ]);
        $login->assertStatus(202)->assertJsonPath('data.requires_2fa', true);
        $token = $login->json('data.token');
        $this->assertIsString($token);

        $limited = null;
        for ($attempt = 1; $attempt <= 15; $attempt++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
                ->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/auth/2fa/verify', [
                    'code' => '000000',
                    'client_type' => 'operator_android',
                ]);
            if ($response->getStatusCode() === 429) {
                $limited = $response;
                break;
            }
        }

        $this->assertNotNull($limited, 'Repeated invalid 2FA verification must return 429.');
        $limited->assertStatus(429)->assertHeader('Retry-After');
        $this->assertContains($limited->json('error.code'), ['rate_limited', 'two_factor_challenge_locked']);
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        $this->assertStringNotContainsString('000000', $limited->getContent());

        $this->travel(11)->minutes();

        $loginAfterDecay = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'Correct-password-123!',
                'device_name' => 'DIS Android security test',
                'client_type' => 'operator_android',
            ]);
        $loginAfterDecay->assertStatus(202)->assertJsonPath('data.requires_2fa', true);
        $freshToken = $loginAfterDecay->json('data.token');
        $this->assertIsString($freshToken);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
            ->withHeader('Authorization', 'Bearer '.$freshToken)
            ->postJson('/api/auth/2fa/verify', [
                'code' => 'VALID-RECOVERY',
                'client_type' => 'operator_android',
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token']]);
    }

    private function login(string $email, string $password, string $ip): TestResponse
    {
        return $this->withServerVariables([
            'REMOTE_ADDR' => $ip,
            'HTTPS' => 'on',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => 'DIS security test',
            // Stateless mobile login reaches the authentication limit directly.
            // Stateful web login is intentionally rejected by CSRF before credentials are checked.
            'client_type' => 'operator_android',
        ]);
    }

    private function assertRateLimited(TestResponse $response, string $errorCode = 'rate_limited'): void
    {
        $response->assertStatus(429)
            ->assertJsonPath('error.code', $errorCode)
            ->assertHeader('Retry-After');

        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertStringNotContainsString('example.test', $response->getContent());
    }
}
