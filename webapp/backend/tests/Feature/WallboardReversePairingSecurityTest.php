<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPairingRequest;
use App\Models\WallboardSession;
use App\Services\WallboardPairingService;
use App\Services\WallboardSessionService;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WallboardReversePairingSecurityTest extends TestCase
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
            'dis.wallboards.pairing_ttl_seconds' => 300,
            'dis.wallboards.credential_cookie_days' => 365,
        ]);
    }

    public function test_tv_start_requires_first_party_state_and_csrf(): void
    {
        $this->withHeaders($this->browserHeaders())
            ->withServerVariables($this->serverVariables())
            ->postJson('/api/wallboard/pairing/start')
            ->assertStatus(419);

        $this->resetHttpState();
        $this->postJson('/api/wallboard/pairing/start')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'stateful_web_session_required');

        $this->assertDatabaseCount('wallboard_pairing_requests', 0);
        $this->assertDatabaseCount('wallboard_sessions', 0);

        $this->initializeCsrf();
        $this->startPairing()->assertOk();
        $this->browserJson('POST', '/api/wallboard/pairing/status', includeCsrf: false)
            ->assertStatus(419);

        $this->resetHttpState();
        $this->postJson('/api/wallboard/pairing/status')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'stateful_web_session_required');
        $this->assertDatabaseCount('wallboard_sessions', 0);
    }

    public function test_tv_start_is_idempotent_and_exposes_only_the_human_code(): void
    {
        $this->initializeCsrf();
        $first = $this->startPairing('Scherm zonder toetsenbord')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.poll_after_seconds', 2);
        $code = (string) $first->json('data.code');
        $this->assertMatchesRegularExpression('/\A[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}\z/', $code);

        $cookie = $first->getCookie(WallboardPairingService::COOKIE_NAME, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', strtolower((string) $cookie->getSameSite()));
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertGreaterThan(time(), $cookie->getExpiresTime());

        $decrypted = $first->getCookie(WallboardPairingService::COOKIE_NAME, true);
        $this->assertNotNull($decrypted);
        [$requestId, $temporarySecret] = explode('.', (string) $decrypted->getValue(), 2);
        $pairingRequest = WallboardPairingRequest::query()->findOrFail($requestId);
        $this->assertSame(64, strlen((string) $pairingRequest->code_hash));
        $this->assertSame(64, strlen((string) $pairingRequest->secret_hash));
        $this->assertNotSame($code, $pairingRequest->code_hash);
        $this->assertNotSame($temporarySecret, $pairingRequest->secret_hash);
        $this->assertStringNotContainsString($temporarySecret, $first->getContent());

        $second = $this->startPairing('Genegeerde nieuwe naam')->assertOk();
        $this->assertSame($code, $second->json('data.code'));
        $this->assertSame('Scherm zonder toetsenbord', $pairingRequest->refresh()->device_name);
        $this->assertDatabaseCount('wallboard_pairing_requests', 1);
        $this->assertDatabaseCount('wallboard_sessions', 0);
    }

    public function test_pending_status_never_leaks_a_wallboard_or_configuration(): void
    {
        $wallboard = $this->wallboard('GEHEIME-COMMANDORUIMTE');
        $this->initializeCsrf();
        $this->startPairing();

        $status = $this->browserJson('POST', '/api/wallboard/pairing/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.wallboard')
            ->assertJsonMissingPath('data.wallboard_id');

        $this->assertStringNotContainsString((string) $wallboard->id, $status->getContent());
        $this->assertStringNotContainsString('GEHEIME-COMMANDORUIMTE', $status->getContent());
        $this->assertNull($status->getCookie(WallboardSessionService::COOKIE_NAME, false));
        $this->assertStringContainsString('no-store', (string) $status->headers->get('Cache-Control'));
    }

    public function test_admin_approval_and_tv_consumption_are_atomic_one_time_and_response_loss_safe(): void
    {
        $wallboard = $this->wallboard('Crisisruimte noord');
        $oldSession = $this->wallboardSession($wallboard, 'oude-sessie');
        $manager = $this->user('pairing-manager@example.test', ['wallboards.manage']);
        $this->initializeCsrf();
        $start = $this->startPairing();
        $code = (string) $start->json('data.code');
        $temporaryCookie = $this->browserCookies[WallboardPairingService::COOKIE_NAME];
        $temporaryCredential = (string) $start
            ->getCookie(WallboardPairingService::COOKIE_NAME, true)?->getValue();
        [, $temporarySecret] = explode('.', $temporaryCredential, 2);

        $approval = $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/pair', [
                'code' => strtolower(str_replace('-', '', $code)),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.wallboard_id', $wallboard->id);
        $this->assertNull($approval->getCookie(WallboardSessionService::COOKIE_NAME, false));
        $this->assertNull($approval->getCookie(WallboardPairingService::COOKIE_NAME, false));
        $this->assertNull($oldSession->refresh()->revoked_at);
        $this->assertNull(WallboardPairingRequest::query()->firstOrFail()->consumed_at);

        $firstConsumption = $this->browserJson('POST', '/api/wallboard/pairing/status')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'paired']])
            ->assertCookieExpired(WallboardPairingService::COOKIE_NAME);
        $permanentCookie = $firstConsumption->getCookie(WallboardSessionService::COOKIE_NAME, false);
        $this->assertNotNull($permanentCookie);
        $this->assertTrue($permanentCookie->isSecure());
        $this->assertTrue($permanentCookie->isHttpOnly());
        $this->assertSame('strict', strtolower((string) $permanentCookie->getSameSite()));
        $firstPermanentCredential = (string) $firstConsumption
            ->getCookie(WallboardSessionService::COOKIE_NAME, true)?->getValue();

        $pairingRequest = WallboardPairingRequest::query()->firstOrFail();
        $this->assertNotNull($pairingRequest->consumed_at);
        $this->assertNotNull($pairingRequest->wallboard_session_id);
        $this->assertNotNull($oldSession->refresh()->revoked_at);
        $this->assertDatabaseCount('wallboard_sessions', 2);
        $newSession = WallboardSession::query()->findOrFail($pairingRequest->wallboard_session_id);
        $this->assertSame($wallboard->id, $newSession->wallboard_id);
        $this->assertNull($newSession->expires_at);
        $this->assertStringNotContainsString('Crisisruimte noord', $firstConsumption->getContent());

        $auditPayload = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString($code, $auditPayload);
        $this->assertStringNotContainsString($temporarySecret, $auditPayload);
        foreach (['wallboards.pairing_requested', 'wallboards.pairing_approved', 'wallboards.paired'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->exists(), $action);
        }

        // Simulate a lost first HTTP response: the TV repeats the poll with the
        // still-present temporary cookie. The existing session is reissued,
        // never recreated, and the plaintext still never enters JSON.
        $this->browserCookies[WallboardPairingService::COOKIE_NAME] = $temporaryCookie;
        $retry = $this->browserJson('POST', '/api/wallboard/pairing/status')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'paired']]);
        $this->assertSame(
            $firstPermanentCredential,
            (string) $retry->getCookie(WallboardSessionService::COOKIE_NAME, true)?->getValue(),
        );
        $this->assertDatabaseCount('wallboard_sessions', 2);
        $this->assertSame($newSession->id, WallboardPairingRequest::query()->firstOrFail()->wallboard_session_id);

        // A full page reload normally calls start before it resumes status
        // polling. With the response lost, the temporary cookie is still
        // present, so start must recover the same approved request and code.
        $this->browserCookies[WallboardPairingService::COOKIE_NAME] = $temporaryCookie;
        $reloadStart = $this->startPairing()
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.code', $code);
        $this->assertDatabaseCount('wallboard_pairing_requests', 1);
        $reloadStatus = $this->browserJson('POST', '/api/wallboard/pairing/status')
            ->assertOk()
            ->assertExactJson(['data' => ['status' => 'paired']]);
        $this->assertSame(
            $firstPermanentCredential,
            (string) $reloadStatus->getCookie(WallboardSessionService::COOKIE_NAME, true)?->getValue(),
        );
        $this->assertDatabaseCount('wallboard_sessions', 2);
    }

    public function test_admin_auth_2fa_permission_disabled_expired_and_immutable_rules_are_enforced(): void
    {
        $firstWallboard = $this->wallboard('Eerste wallboard');
        $secondWallboard = $this->wallboard('Tweede wallboard');
        $this->initializeCsrf();
        $code = (string) $this->startPairing()->json('data.code');

        $this->postJson('/api/admin/wallboards/'.$firstWallboard->id.'/pair', ['code' => $code])
            ->assertUnauthorized();

        $unprivileged = $this->user('pairing-unprivileged@example.test', []);
        $this->asAdminClient($unprivileged)
            ->postJson('/api/admin/wallboards/'.$firstWallboard->id.'/pair', ['code' => $code])
            ->assertForbidden();

        $manager = $this->user('pairing-authorized@example.test', ['wallboards.manage']);
        $pendingToken = $manager->createToken(
            'Pending wallboard pairing 2FA',
            ['2fa:pending', 'client:admin'],
            now()->addMinutes(5),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($pendingToken)
            ->postJson('/api/admin/wallboards/'.$firstWallboard->id.'/pair', ['code' => $code])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$firstWallboard->id.'/pair', ['code' => $code])
            ->assertOk();
        $pairingRequest = WallboardPairingRequest::query()->firstOrFail();

        $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$secondWallboard->id.'/pair', ['code' => $code])
            ->assertUnprocessable();
        $this->assertSame($firstWallboard->id, $pairingRequest->refresh()->wallboard_id);
        $this->assertSame($manager->id, $pairingRequest->approved_by);

        unset($this->browserCookies[WallboardPairingService::COOKIE_NAME]);
        $disabledCode = (string) $this->startPairing()->json('data.code');
        $secondWallboard->forceFill(['is_enabled' => false])->save();
        $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$secondWallboard->id.'/pair', ['code' => $disabledCode])
            ->assertUnprocessable();

        unset($this->browserCookies[WallboardPairingService::COOKIE_NAME]);
        $expiredCode = (string) $this->startPairing()->json('data.code');
        WallboardPairingRequest::query()
            ->where('code_hash', app(WallboardPairingService::class)->codeHash($expiredCode))
            ->firstOrFail()
            ->forceFill(['expires_at' => now()->subSecond()])
            ->save();
        $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$firstWallboard->id.'/pair', ['code' => $expiredCode])
            ->assertUnprocessable();
    }

    public function test_expired_tampered_deleted_and_disabled_pairings_clear_the_temporary_cookie(): void
    {
        $manager = $this->user('pairing-invalidations@example.test', ['wallboards.manage']);
        $this->initializeCsrf();
        $this->startPairing();
        WallboardPairingRequest::query()->firstOrFail()
            ->forceFill(['expires_at' => now()->subSecond()])
            ->save();
        $this->assertPairingRejectedAndCleared();

        unset($this->browserCookies[WallboardPairingService::COOKIE_NAME]);
        $start = $this->startPairing();
        $rawCookie = $this->browserCookies[WallboardPairingService::COOKIE_NAME];
        $middle = intdiv(strlen($rawCookie), 2);
        $this->browserCookies[WallboardPairingService::COOKIE_NAME] = substr($rawCookie, 0, $middle)
            .($rawCookie[$middle] === 'A' ? 'B' : 'A')
            .substr($rawCookie, $middle + 1);
        $this->assertPairingRejectedAndCleared();

        unset($this->browserCookies[WallboardPairingService::COOKIE_NAME]);
        $wallboard = $this->wallboard('Te verwijderen target');
        $code = (string) $this->startPairing()->json('data.code');
        $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/pair', ['code' => $code])
            ->assertOk();
        $wallboard->delete();
        $rejected = $this->assertPairingRejectedAndCleared();
        $this->assertStringNotContainsString('Te verwijderen target', $rejected->getContent());
        $this->assertDatabaseCount('wallboard_sessions', 0);

        unset($this->browserCookies[WallboardPairingService::COOKIE_NAME]);
        $disabled = $this->wallboard('Uitgeschakeld target');
        $disabledCode = (string) $this->startPairing()->json('data.code');
        $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$disabled->id.'/pair', ['code' => $disabledCode])
            ->assertOk();
        $disabled->forceFill(['is_enabled' => false])->save();
        $this->assertPairingRejectedAndCleared();
        $this->assertDatabaseCount('wallboard_sessions', 0);
    }

    public function test_pairing_rate_limits_normalize_admin_codes_and_emit_retry_after(): void
    {
        $this->initializeCsrf();
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->startPairing()->assertOk();
        }
        $limitedStart = $this->startPairing()->assertTooManyRequests();
        $this->assertNotNull($limitedStart->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $limitedStart->headers->get('Cache-Control'));

        $manager = $this->user('pairing-rate-limit@example.test', ['wallboards.manage']);
        $wallboard = $this->wallboard('Rate limit target');
        foreach (['ABCD-EFGH', 'abcdefgh', 'ABCD EFGH', 'abcd-efgh', 'ABCDEFGH'] as $variant) {
            $this->asAdminClient($manager)
                ->postJson('/api/admin/wallboards/'.$wallboard->id.'/pair', ['code' => $variant])
                ->assertUnprocessable();
        }
        $limitedApproval = $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboards/'.$wallboard->id.'/pair', ['code' => 'abcd efgh'])
            ->assertTooManyRequests();
        $this->assertNotNull($limitedApproval->headers->get('Retry-After'));
    }

    public function test_pruning_removes_only_expired_or_old_consumed_pairing_requests(): void
    {
        $this->initializeCsrf();
        $this->startPairing();
        $active = WallboardPairingRequest::query()->firstOrFail();

        $expired = $active->replicate();
        $expired->id = (string) str()->ulid();
        $expired->code_hash = hash('sha256', 'expired-code');
        $expired->secret_hash = hash('sha256', 'expired-secret');
        $expired->expires_at = now()->subSecond();
        $expired->save();

        $oldConsumed = $active->replicate();
        $oldConsumed->id = (string) str()->ulid();
        $oldConsumed->code_hash = hash('sha256', 'consumed-code');
        $oldConsumed->secret_hash = hash('sha256', 'consumed-secret');
        $oldConsumed->consumed_at = now()->subDays(2);
        $oldConsumed->expires_at = now()->addMinute();
        $oldConsumed->save();

        $this->artisan('dis:prune-operational-data')->assertSuccessful();

        $this->assertDatabaseHas('wallboard_pairing_requests', ['id' => $active->id]);
        $this->assertDatabaseMissing('wallboard_pairing_requests', ['id' => $expired->id]);
        $this->assertDatabaseMissing('wallboard_pairing_requests', ['id' => $oldConsumed->id]);
    }

    private function startPairing(?string $deviceName = null): TestResponse
    {
        return $this->browserJson('POST', '/api/wallboard/pairing/start', array_filter([
            'device_name' => $deviceName,
        ], fn (mixed $value): bool => $value !== null));
    }

    private function assertPairingRejectedAndCleared(): TestResponse
    {
        return $this->browserJson('POST', '/api/wallboard/pairing/status')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'wallboard_pairing_unauthenticated')
            ->assertCookieExpired(WallboardPairingService::COOKIE_NAME);
    }

    private function wallboard(string $name): Wallboard
    {
        return Wallboard::query()->create([
            'name' => $name,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::defaults(),
            'is_enabled' => true,
        ]);
    }

    private function wallboardSession(Wallboard $wallboard, string $seed): WallboardSession
    {
        return WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash('sha256', $seed),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'Wallboard Pairing User',
            'first_name' => 'Wallboard',
            'last_name' => 'Pairing User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-pairing-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Wallboard pairing test role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                ['display_name' => $permissionName, 'category' => 'system_configuration', 'description' => 'Test permission'],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Wallboard pairing admin test', ['*', 'client:admin'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function initializeCsrf(): void
    {
        $this->browserJson('GET', '/api/auth/csrf-cookie', includeCsrf: false)
            ->assertNoContent();
        $this->assertArrayHasKey('XSRF-TOKEN', $this->browserCookies);
        $this->assertNotNull($this->csrfHeader);
    }

    /** @param array<string, mixed> $data */
    private function browserJson(
        string $method,
        string $uri,
        array $data = [],
        bool $includeCsrf = true,
    ): TestResponse {
        $this->resetHttpState();

        $headers = $this->browserHeaders();
        if ($includeCsrf && $this->csrfHeader !== null) {
            $headers['X-XSRF-TOKEN'] = $this->csrfHeader;
        }

        $response = $this->withCredentials()
            ->withUnencryptedCookies($this->browserCookies)
            ->withHeaders($headers)
            ->withServerVariables($this->serverVariables())
            ->json($method, $uri, $data);

        $this->captureCookies($response);

        return $response;
    }

    private function resetHttpState(): void
    {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];
    }

    /** @return array<string, string> */
    private function browserHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Origin' => self::ORIGIN,
            'Referer' => self::ORIGIN.'/',
            'Sec-Fetch-Site' => 'same-origin',
            'X-Requested-With' => 'XMLHttpRequest',
        ];
    }

    /** @return array<string, int|string> */
    private function serverVariables(): array
    {
        return [
            'HTTP_HOST' => 'dis.example.test',
            'SERVER_NAME' => 'dis.example.test',
            'SERVER_PORT' => 443,
            'HTTPS' => 'on',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '192.0.2.88',
        ];
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
}
