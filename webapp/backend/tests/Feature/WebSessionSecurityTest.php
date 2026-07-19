<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Services\UserService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WebSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://dis.example.test';

    /** @var array<string, string> */
    private array $browserCookies = [];

    private ?string $csrfHeader = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::ORIGIN,
            'session.driver' => 'database',
            'session.connection' => null,
            'session.table' => 'sessions',
            'session.trusted_origins' => [self::ORIGIN],
            'sanctum.stateful' => ['dis.example.test'],
        ]);
    }

    public function test_web_login_uses_a_hardened_rotated_cookie_and_never_returns_a_token(): void
    {
        $this->setMfaRequired(true);
        $user = $this->user('cookie-login@example.test', mfaEnabled: true, recoveryCodes: ['COOKIE-12345']);

        $csrf = $this->initializeCsrf();
        $anonymousSessionId = $this->sessionId($csrf);

        $login = $this->browserJson('POST', '/api/auth/login', [
            'email' => $user->email,
            'password' => 'Test-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ]);

        $login->assertStatus(202)->assertJsonPath('data.requires_2fa', true);
        $this->assertPayloadHasNoKey($login->json(), 'token');

        $sessionCookie = $login->getCookie($this->sessionCookieName(), false);
        $this->assertNotNull($sessionCookie);
        $this->assertStringStartsWith('__Host-', $sessionCookie->getName());
        $this->assertTrue($sessionCookie->isSecure());
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertSame('/', $sessionCookie->getPath());
        $this->assertNull($sessionCookie->getDomain());
        $this->assertContains(strtolower((string) $sessionCookie->getSameSite()), ['lax', 'strict']);
        $this->assertGreaterThan(time(), $sessionCookie->getExpiresTime());

        $authenticatedSessionId = $this->sessionId($login);
        $this->assertNotSame($anonymousSessionId, $authenticatedSessionId, 'Login must rotate the session identifier.');
        $this->assertDatabaseMissing('sessions', ['id' => $anonymousSessionId]);
        $this->assertDatabaseHas('sessions', ['id' => $authenticatedSessionId, 'user_id' => null]);
    }

    public function test_stateful_browser_cannot_request_a_mobile_bearer_token(): void
    {
        $this->setMfaRequired(false);
        $user = $this->user('browser-mobile-token@example.test', mfaEnabled: false);

        $this->initializeCsrf();
        $response = $this->browserJson('POST', '/api/auth/login', [
            'email' => $user->email,
            'password' => 'Test-password-123!',
            'device_name' => 'DIS Operator Android',
            'client_type' => 'operator_android',
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'invalid_client_context');
        $this->assertPayloadHasNoKey($response->json(), 'token');
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_web_login_without_a_stateful_first_party_context_returns_the_intended_forbidden_response(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'non-stateful-web@example.test',
            'password' => 'Test-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertForbidden()
            ->assertJsonPath('error.code', 'stateful_web_session_required');
    }

    public function test_pending_mobile_two_factor_token_cannot_authorize_broadcast_channels(): void
    {
        $user = $this->user('pending-broadcast@example.test', mfaEnabled: true);
        $token = $user->createToken('DIS Admin Android', ['2fa:pending', 'client:admin'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/broadcasting/auth', [
                'socket_id' => '123.456',
                'channel_name' => 'private-operations',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');
    }

    public function test_pre_two_factor_session_is_gated_and_successful_verification_rotates_it_again(): void
    {
        $this->setMfaRequired(true);
        $user = $this->user('mfa-login@example.test', mfaEnabled: true, recoveryCodes: ['RECOV-12345']);

        $this->initializeCsrf();
        $login = $this->browserJson('POST', '/api/auth/login', [
            'email' => $user->email,
            'password' => 'Test-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ]);

        $login->assertStatus(202)->assertJsonPath('data.requires_2fa', true);
        $this->assertPayloadHasNoKey($login->json(), 'token');
        $preAuthSessionId = $this->sessionId($login);

        $this->browserJson('GET', '/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $verification = $this->browserJson('POST', '/api/auth/2fa/verify', [
            'code' => 'RECOV-12345',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ]);

        $verification->assertOk();
        $this->assertPayloadHasNoKey($verification->json(), 'token');
        $authenticatedSessionId = $this->sessionId($verification);
        $this->assertNotSame($preAuthSessionId, $authenticatedSessionId, 'Completing 2FA must rotate the session identifier.');
        $this->assertDatabaseMissing('sessions', ['id' => $preAuthSessionId]);
        $this->assertDatabaseHas('sessions', ['id' => $authenticatedSessionId, 'user_id' => $user->id]);

        $this->browserJson('GET', '/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'auth.login_succeeded',
        ]);
    }

    public function test_authenticator_totp_completes_the_web_login_challenge(): void
    {
        $this->setMfaRequired(true);
        $secret = 'JBSWY3DPEHPK3PXP';
        $user = $this->user('totp-login@example.test', mfaEnabled: true);

        $this->initializeCsrf();
        $this->browserJson('POST', '/api/auth/login', [
            'email' => $user->email,
            'password' => 'Test-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertStatus(202)->assertJsonPath('data.requires_2fa', true);

        $verification = $this->browserJson('POST', '/api/auth/2fa/verify', [
            'code' => $this->currentTotpCode($secret),
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ]);

        $verification->assertOk()->assertJsonPath('data.authenticated', true);
        $this->assertSame([], $user->refresh()->two_factor_recovery_codes);
        $this->browserJson('GET', '/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_store_review_logout_revokes_the_current_access_token(): void
    {
        $user = User::query()->create([
            'name' => 'Store Review User',
            'first_name' => 'Store',
            'last_name' => 'Review User',
            'email' => 'store-review-logout@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'store_review',
            'two_factor_enabled' => false,
        ]);
        $issuedToken = $user->createToken('Google Play Review', ['client:store_review'], now()->addHour());

        $this->withToken($issuedToken->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $issuedToken->accessToken->id]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'auth.logout',
        ]);
    }

    public function test_logout_invalidates_server_session_expires_cookie_and_is_audited(): void
    {
        $user = $this->authenticateBrowserUser('logout@example.test', 'LOGOUT-12345');
        $sessionId = $this->currentSessionId();
        $authenticatedCookies = $this->browserCookies;

        $logout = $this->browserJson('POST', '/api/auth/logout');

        $logout->assertNoContent()->assertCookieExpired($this->sessionCookieName());
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $user->id,
            'action' => 'auth.logout',
        ]);

        $this->browserCookies = $authenticatedCookies;
        $this->browserJson('GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_tampered_session_cookie_is_rejected_without_exposing_internal_errors(): void
    {
        $user = $this->authenticateBrowserUser('tampered-cookie@example.test', 'TAMPER-12345');
        $cookieName = $this->sessionCookieName();
        $originalCookie = $this->browserCookies[$cookieName] ?? null;
        $this->assertIsString($originalCookie);
        $this->assertNotSame('', $originalCookie);

        $middle = intdiv(strlen($originalCookie), 2);
        $replacement = $originalCookie[$middle] === 'A' ? 'B' : 'A';
        $this->browserCookies[$cookieName] = substr($originalCookie, 0, $middle)
            .$replacement
            .substr($originalCookie, $middle + 1);

        $tamperedResponse = $this->browserJson('GET', '/api/auth/me');
        $tamperedResponse->assertUnauthorized()->assertJsonPath('error.code', 'session_expired');
        $this->assertStringNotContainsString('DecryptException', $tamperedResponse->getContent());
        $this->assertStringNotContainsString($user->email, $tamperedResponse->getContent());
    }

    public function test_privilege_changes_invalidate_an_existing_web_session(): void
    {
        $user = $this->authenticateBrowserUser('role-change@example.test', 'ROLE-12345');
        $sessionId = $this->currentSessionId();

        $actor = $this->user('role-manager@example.test', mfaEnabled: true);
        $rolesManage = Permission::query()->firstOrCreate(
            ['name' => 'roles.manage'],
            [
                'category' => 'security-test',
                'display_name' => 'Manage roles',
                'description' => 'Security contract test permission',
            ],
        );
        $administrator = Role::query()->firstOrCreate(
            ['name' => Role::SYSTEM_ADMINISTRATOR],
            [
                'display_name' => 'System administrator',
                'can_use_operator_app' => false,
                'can_use_admin_app' => true,
            ],
        );
        $administrator->forceFill(['can_use_admin_app' => true])->save();
        $administrator->permissions()->syncWithoutDetaching([$rolesManage->id => ['created_at' => now()]]);
        $actor->roles()->syncWithoutDetaching([$administrator->id => ['created_at' => now()]]);

        $replacementRole = Role::query()->create([
            'name' => 'replacement-web-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Replacement web role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);

        app(UserService::class)->update($user, ['role_ids' => [$replacementRole->id]], $actor);

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->browserJson('GET', '/api/auth/me')->assertUnauthorized();
    }

    public function test_registration_invitation_is_exchanged_once_for_server_side_session_state(): void
    {
        $this->setMfaRequired(false);
        $user = User::query()->create([
            'name' => 'Invited Security User',
            'first_name' => 'Invited',
            'last_name' => 'Security User',
            'email' => 'registration-session@example.test',
            'password' => Hash::make('Temporary-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => false,
        ]);
        $token = Password::broker()->createToken($user);

        $this->initializeCsrf();
        $invite = $this->browserJson('POST', '/api/registration/invite', [
            'email' => $user->email,
            'token' => $token,
        ]);

        $invite->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertPayloadHasNoKey($invite->json(), 'token');
        $preRegistrationSessionId = $this->sessionId($invite);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $pairingId = (string) str()->ulid();
        DB::table('mobile_pairing_codes')->insert([
            'id' => $pairingId,
            'user_id' => $user->id,
            'code_hash' => hash('sha256', 'registration-pending-pairing'),
            'client_type' => 'operator',
            'expires_at' => now()->addSeconds(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->browserJson('POST', '/api/registration/invite', [
            'email' => $user->email,
            'token' => $token,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_invitation');

        $complete = $this->browserJson('POST', '/api/registration/complete', [
            'password' => 'New-Test-password-123!',
            'password_confirmation' => 'New-Test-password-123!',
        ]);

        $complete->assertOk()->assertJsonPath('data.authenticated', true);
        $this->assertPayloadHasNoKey($complete->json(), 'token');
        $this->assertTrue(Hash::check('New-Test-password-123!', (string) $user->refresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => $preRegistrationSessionId]);
        $this->assertDatabaseMissing('mobile_pairing_codes', ['id' => $pairingId]);
        $this->browserJson('POST', '/api/registration/complete', [
            'password' => 'Another-Test-password-123!',
            'password_confirmation' => 'Another-Test-password-123!',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'invalid_invitation');
    }

    public function test_registration_session_is_rejected_after_account_block_and_version_change(): void
    {
        $this->setMfaRequired(false);
        $originalPassword = 'Temporary-password-123!';
        $user = User::query()->create([
            'name' => 'Blocked Invitation User',
            'first_name' => 'Blocked',
            'last_name' => 'Invitation User',
            'email' => 'blocked-registration-session@example.test',
            'password' => Hash::make($originalPassword),
            'account_status' => 'active',
            'two_factor_enabled' => false,
        ]);
        $token = Password::broker()->createToken($user);

        $this->initializeCsrf();
        $invite = $this->browserJson('POST', '/api/registration/invite', [
            'email' => $user->email,
            'token' => $token,
        ]);
        $invite->assertOk();
        $preRegistrationSessionId = $this->sessionId($invite);

        $user->forceFill([
            'account_status' => 'blocked',
            'auth_session_version' => (int) $user->auth_session_version + 1,
        ])->save();

        $complete = $this->browserJson('POST', '/api/registration/complete', [
            'password' => 'New-Test-password-123!',
            'password_confirmation' => 'New-Test-password-123!',
        ]);

        $complete->assertUnprocessable()->assertJsonPath('error.code', 'invalid_invitation');
        $this->assertPayloadHasNoKey($complete->json(), 'token');
        $this->assertTrue(Hash::check($originalPassword, (string) $user->refresh()->password));
        $this->assertSame('blocked', $user->account_status);
        $this->assertDatabaseMissing('sessions', ['id' => $preRegistrationSessionId]);
    }

    public function test_deleted_idle_expired_and_absolute_expired_server_sessions_are_rejected(): void
    {
        $this->authenticateBrowserUser('revocation@example.test', 'REVOK-12345');
        $sessionId = $this->currentSessionId();

        DB::table('sessions')->where('id', $sessionId)->delete();
        $this->browserJson('GET', '/api/auth/me')->assertUnauthorized();

        $this->resetBrowserState();
        config(['session.lifetime' => 1]);
        $this->authenticateBrowserUser('idle-timeout@example.test', 'IDLE-12345');
        $expiredSessionId = $this->currentSessionId();
        $this->travel(2)->minutes();

        $this->browserJson('GET', '/api/auth/me')->assertUnauthorized();
        $this->assertDatabaseMissing('sessions', ['id' => $expiredSessionId]);

        $this->travelBack();
        $this->resetBrowserState();
        config(['session.lifetime' => 120, 'session.absolute_lifetime' => 1]);
        $this->authenticateBrowserUser('absolute-timeout@example.test', 'ABSOL-12345');
        $absoluteExpiredSessionId = $this->currentSessionId();
        $this->travel(2)->minutes();

        $this->browserJson('GET', '/api/auth/me')->assertUnauthorized();
        $this->assertDatabaseMissing('sessions', ['id' => $absoluteExpiredSessionId]);
    }

    public function test_real_browser_activity_extends_idle_lifetime_without_rotating_or_extending_absolute_lifetime(): void
    {
        config([
            'session.lifetime' => 2,
            'session.absolute_lifetime' => 4,
        ]);
        $this->authenticateBrowserUser('active-session@example.test', 'ACTIVE-12345');
        $sessionId = $this->currentSessionId();

        $this->travel(90)->seconds();
        $this->browserJson('POST', '/api/auth/session/touch')->assertNoContent();
        $this->assertSame($sessionId, $this->currentSessionId(), 'Activity must not rotate an already authenticated session.');

        $this->travel(90)->seconds();
        $this->browserJson('GET', '/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'active-session@example.test');
        $this->assertSame($sessionId, $this->currentSessionId());

        $this->travel(61)->seconds();
        $this->browserJson('POST', '/api/auth/session/touch')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'session_expired');
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
    }

    private function initializeCsrf(): TestResponse
    {
        $response = $this->browserJson('GET', '/api/auth/csrf-cookie', includeCsrf: false);
        $response->assertNoContent();
        $this->assertArrayHasKey('XSRF-TOKEN', $this->browserCookies);
        $this->assertNotNull($this->csrfHeader);

        return $response;
    }

    private function authenticateBrowserUser(string $email, string $recoveryCode): User
    {
        $this->setMfaRequired(true);
        $user = $this->user($email, mfaEnabled: true, recoveryCodes: [$recoveryCode]);
        $this->initializeCsrf();
        $this->browserJson('POST', '/api/auth/login', [
            'email' => $user->email,
            'password' => 'Test-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertStatus(202)->assertJsonPath('data.requires_2fa', true);
        $this->browserJson('POST', '/api/auth/2fa/verify', [
            'code' => $recoveryCode,
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertOk();

        return $user;
    }

    private function browserJson(
        string $method,
        string $uri,
        array $data = [],
        bool $includeCsrf = true,
    ): TestResponse {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        $headers = [
            'Accept' => 'application/json',
            'Origin' => self::ORIGIN,
            'Referer' => self::ORIGIN.'/',
            'Sec-Fetch-Site' => 'same-origin',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
        if ($includeCsrf && $this->csrfHeader !== null) {
            $headers['X-XSRF-TOKEN'] = $this->csrfHeader;
        }

        $response = $this->withCredentials()
            ->withUnencryptedCookies($this->browserCookies)
            ->withHeaders($headers)
            ->withServerVariables([
                'HTTP_HOST' => 'dis.example.test',
                'SERVER_NAME' => 'dis.example.test',
                'SERVER_PORT' => 443,
                'HTTPS' => 'on',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REMOTE_ADDR' => '192.0.2.50',
            ])
            ->json($method, $uri, $data);

        $this->captureCookies($response);

        return $response;
    }

    private function captureCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() <= time()) {
                unset($this->browserCookies[$cookie->getName()]);

                continue;
            }

            $this->browserCookies[$cookie->getName()] = $cookie->getValue();
            if ($cookie->getName() === 'XSRF-TOKEN') {
                $this->csrfHeader = rawurldecode($cookie->getValue());
            }
        }
    }

    private function sessionId(TestResponse $response): string
    {
        $cookie = $response->getCookie($this->sessionCookieName(), true);
        $this->assertNotNull($cookie, 'The response must contain the session cookie.');

        return (string) $cookie->getValue();
    }

    private function sessionCookieName(): string
    {
        return (string) config('session.cookie');
    }

    private function currentSessionId(): string
    {
        $rawCookie = $this->browserCookies[$this->sessionCookieName()] ?? null;
        $this->assertNotNull($rawCookie, 'The browser must hold a session cookie.');

        return CookieValuePrefix::remove((string) decrypt($rawCookie, false));
    }

    private function resetBrowserState(): void
    {
        $this->browserCookies = [];
        $this->csrfHeader = null;
    }

    private function setMfaRequired(bool $required): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => TwoFactorService::REQUIRED_KEY],
            ['value' => $required, 'is_sensitive' => false],
        );
    }

    /**
     * @param  list<string>  $recoveryCodes
     */
    private function user(string $email, bool $mfaEnabled, array $recoveryCodes = []): User
    {
        $user = User::query()->create([
            'name' => 'Web Security User',
            'first_name' => 'Web',
            'last_name' => 'Security User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => $mfaEnabled,
            'two_factor_secret' => $mfaEnabled ? 'JBSWY3DPEHPK3PXP' : null,
            'two_factor_recovery_codes' => $mfaEnabled ? $recoveryCodes : null,
            'two_factor_confirmed_at' => $mfaEnabled ? now() : null,
        ]);
        $role = Role::query()->create([
            'name' => 'web-security-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Web security role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function assertPayloadHasNoKey(mixed $payload, string $forbiddenKey): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            $this->assertNotSame($forbiddenKey, (string) $key, "Response JSON must not contain a {$forbiddenKey} field.");
            $this->assertPayloadHasNoKey($value, $forbiddenKey);
        }
    }

    private function currentTotpCode(string $secret): string
    {
        $counter = intdiv(time(), 30);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $this->decodeBase32($secret), true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function decodeBase32(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';

        foreach (str_split(strtoupper($secret)) as $character) {
            $position = strpos($alphabet, $character);
            $this->assertNotFalse($position);
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
