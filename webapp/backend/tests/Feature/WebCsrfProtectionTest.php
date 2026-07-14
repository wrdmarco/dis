<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WebCsrfProtectionTest extends TestCase
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
        SystemSetting::query()->updateOrCreate(
            ['key' => TwoFactorService::REQUIRED_KEY],
            ['value' => false, 'is_sensitive' => false],
        );
    }

    public function test_session_authenticated_mutations_require_the_session_bound_csrf_token(): void
    {
        $this->loginBrowserUser();

        $this->browserRequest('PATCH', '/api/auth/me', ['theme' => 'light'], includeCsrf: false)
            ->assertStatus(419);
        $this->browserRequest('PATCH', '/api/auth/me', ['theme' => 'light'], csrfOverride: 'tampered-token')
            ->assertStatus(419);

        $this->browserRequest('PATCH', '/api/auth/me', ['theme' => 'light'])
            ->assertOk();
    }

    public function test_session_authenticated_mutations_require_same_origin_fetch_metadata(): void
    {
        $this->loginBrowserUser();

        $this->browserRequest(
            'PATCH',
            '/api/auth/me',
            ['theme' => 'light'],
            includeOrigin: false,
            includeReferer: false,
        )->assertForbidden();

        $this->browserRequest(
            'PATCH',
            '/api/auth/me',
            ['theme' => 'light'],
            origin: 'https://attacker.example',
            referer: 'https://attacker.example/form',
        )->assertForbidden();

        $this->browserRequest(
            'PATCH',
            '/api/auth/me',
            ['theme' => 'light'],
            secFetchSite: 'cross-site',
        )->assertForbidden();
    }

    public function test_session_authenticated_mutations_reject_unexpected_content_types(): void
    {
        $this->loginBrowserUser();

        $this->browserRequest(
            'PATCH',
            '/api/auth/me',
            ['theme' => 'light'],
            contentType: 'text/plain',
        )->assertStatus(415);
    }

    public function test_mobile_bearer_authentication_remains_stateless_and_does_not_require_csrf(): void
    {
        $user = $this->user('mobile-bearer@example.test');
        $role = Role::query()->create([
            'name' => 'security-mobile-operator',
            'display_name' => 'Security mobile operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        $this->resetRequestState();
        $login = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.80'])
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'Test-password-123!',
                'device_name' => 'DIS Android security test',
                'client_type' => 'operator_android',
            ]);

        $login->assertOk();
        $token = $login->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);

        $this->resetRequestState();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertNoContent();
    }

    private function loginBrowserUser(): User
    {
        $recoveryCode = 'CSRF-12345';
        $user = User::query()->create([
            'name' => 'CSRF Browser User',
            'first_name' => 'CSRF',
            'last_name' => 'Browser User',
            'email' => 'csrf-browser-'.strtolower((string) str()->ulid()).'@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => [$recoveryCode],
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'csrf-web-console-'.strtolower((string) str()->ulid()),
            'display_name' => 'CSRF web console role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        $this->browserRequest('GET', '/api/auth/csrf-cookie', includeCsrf: false)->assertNoContent();
        $this->assertNotNull($this->csrfHeader);

        $this->browserRequest('POST', '/api/auth/login', [
            'email' => $user->email,
            'password' => 'Test-password-123!',
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertStatus(202)->assertJsonPath('data.requires_2fa', true);
        $this->browserRequest('POST', '/api/auth/2fa/verify', [
            'code' => $recoveryCode,
            'device_name' => 'DIS Command Center',
            'client_type' => 'web',
        ])->assertOk();

        return $user;
    }

    private function browserRequest(
        string $method,
        string $uri,
        array $data = [],
        bool $includeCsrf = true,
        ?string $csrfOverride = null,
        bool $includeOrigin = true,
        bool $includeReferer = true,
        string $origin = self::ORIGIN,
        string $referer = self::ORIGIN.'/',
        string $secFetchSite = 'same-origin',
        string $contentType = 'application/json',
    ): TestResponse {
        $this->resetRequestState();
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $headers = [
            'Accept' => 'application/json',
            'Sec-Fetch-Site' => $secFetchSite,
            'X-Requested-With' => 'XMLHttpRequest',
        ];
        if ($includeOrigin) {
            $headers['Origin'] = $origin;
        }
        if ($includeReferer) {
            $headers['Referer'] = $referer;
        }
        if ($includeCsrf && ($csrfOverride ?? $this->csrfHeader) !== null) {
            $headers['X-XSRF-TOKEN'] = $csrfOverride ?? $this->csrfHeader;
        }

        $this->withCredentials()
            ->withUnencryptedCookies($this->browserCookies)
            ->withHeaders($headers)
            ->withServerVariables([
                'HTTP_HOST' => 'dis.example.test',
                'SERVER_NAME' => 'dis.example.test',
                'SERVER_PORT' => 443,
                'HTTPS' => 'on',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'REMOTE_ADDR' => '192.0.2.60',
            ]);

        if ($contentType === 'application/json') {
            $response = $this->json($method, $uri, $data);
        } else {
            $server = $this->transformHeadersToServerVars(['Content-Type' => $contentType]);
            $response = $this->call(
                $method,
                $uri,
                [],
                $this->prepareCookiesForRequest(),
                [],
                $server,
                json_encode($data, JSON_THROW_ON_ERROR),
            );
        }

        $this->captureCookies($response);

        return $response;
    }

    private function resetRequestState(): void
    {
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];
        $this->withCredentials = false;
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

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'CSRF Security User',
            'first_name' => 'CSRF',
            'last_name' => 'Security User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => false,
        ]);
    }
}
