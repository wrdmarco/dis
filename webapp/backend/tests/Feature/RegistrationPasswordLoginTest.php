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

    public function test_operator_can_complete_required_mfa_during_registration(): void
    {
        $this->withoutMiddleware(VerifyWebCsrfToken::class);
        config([
            'app.url' => 'https://dis.example.test',
            'session.trusted_origins' => ['https://dis.example.test'],
            'sanctum.stateful' => ['dis.example.test'],
        ]);
        SystemSetting::query()->updateOrCreate(
            ['key' => TwoFactorService::REQUIRED_KEY],
            ['value' => true, 'is_sensitive' => false],
        );
        $user = User::query()->create([
            'name' => 'Registration MFA Test',
            'first_name' => 'Registration',
            'last_name' => 'MFA Test',
            'email' => 'registration-mfa@example.test',
            'password' => Hash::make('Temporary-password-123!'),
            'account_status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => 'registration-mfa-operator',
            'display_name' => 'Registration MFA operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);
        $headers = [
            'Origin' => 'https://dis.example.test',
            'Referer' => 'https://dis.example.test/register',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
        $server = ['HTTP_HOST' => 'dis.example.test', 'HTTPS' => 'on'];

        $complete = $this->withSession([
            WebSessionService::KEY_PENDING_USER_ID => $user->id,
            WebSessionService::KEY_PENDING_PURPOSE => WebSessionService::PURPOSE_REGISTRATION_ACCOUNT,
            WebSessionService::KEY_PENDING_EXPIRES_AT => now()->addMinutes(30)->getTimestamp(),
            WebSessionService::KEY_PENDING_VERSION => (int) $user->auth_session_version,
        ])->withServerVariables($server)->withHeaders($headers)->postJson('/api/registration/complete', [
            'password' => 'New-secure-password-123!',
            'password_confirmation' => 'New-secure-password-123!',
        ]);

        $complete->assertOk()
            ->assertJsonPath('data.authenticated', false)
            ->assertJsonPath('data.two_factor_setup.enabled', false);
        $secret = $complete->json('data.two_factor_setup.secret');
        $this->assertIsString($secret);

        $this->withServerVariables($server)->withHeaders($headers)->postJson('/api/auth/2fa/enable', [
            'code' => $this->totp($secret),
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertTrue($user->refresh()->two_factor_enabled);

        $this->withServerVariables($server)->withHeaders($headers)->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'New-secure-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertStatus(202)->assertJsonPath('data.requires_2fa', true);

        $this->withServerVariables($server)->withHeaders($headers)->postJson('/api/auth/2fa/verify', [
            'code' => '000000',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_two_factor_code');

        $this->withServerVariables($server)->withHeaders($headers)->postJson('/api/auth/2fa/verify', [
            'code' => $this->totp($secret),
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.user.id', $user->id);
    }

    private function totp(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $counter = intdiv(time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0).pack('N*', $counter), $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
