<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyWebCsrfToken;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Services\WebSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class RegistrationPasswordLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_password_allows_operator_to_log_in_to_web_console(): void
    {
        $this->withoutMiddleware(VerifyWebCsrfToken::class);
        config([
            'app.url' => 'https://dis.example.test',
            'session.trusted_origins' => ['https://dis.example.test'],
            'sanctum.stateful' => ['dis.example.test'],
        ]);
        SystemSetting::query()->updateOrCreate(
            ['key' => TwoFactorService::REQUIRED_KEY],
            ['value' => false, 'is_sensitive' => false],
        );
        $user = User::query()->create([
            'name' => 'Registration Login Test',
            'first_name' => 'Registration',
            'last_name' => 'Login Test',
            'email' => 'registration-login@example.test',
            'password' => Hash::make('Temporary-password-123!'),
            'account_status' => 'active',
            'failed_login_attempts' => 5,
            'login_locked_until' => now()->addMinutes(5),
        ]);
        $role = Role::query()->create([
            'name' => 'registration-login-operator',
            'display_name' => 'Registration login operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        $this->withSession([
            WebSessionService::KEY_PENDING_USER_ID => $user->id,
            WebSessionService::KEY_PENDING_PURPOSE => WebSessionService::PURPOSE_REGISTRATION_ACCOUNT,
            WebSessionService::KEY_PENDING_EXPIRES_AT => now()->addMinutes(30)->getTimestamp(),
            WebSessionService::KEY_PENDING_VERSION => (int) $user->auth_session_version,
        ])->withServerVariables([
            'HTTP_HOST' => 'dis.example.test',
            'HTTPS' => 'on',
        ])->withHeaders([
            'Origin' => 'https://dis.example.test',
            'Referer' => 'https://dis.example.test/register',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->postJson('/api/registration/complete', [
            'password' => 'New-secure-password-123!',
            'password_confirmation' => 'New-secure-password-123!',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('New-secure-password-123!', (string) $user->password));
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->login_locked_until);

        $this->withServerVariables([
            'HTTP_HOST' => 'dis.example.test',
            'HTTPS' => 'on',
        ])->withHeaders([
            'Origin' => 'https://dis.example.test',
            'Referer' => 'https://dis.example.test/login',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'New-secure-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.user.id', $user->id);
    }
}
