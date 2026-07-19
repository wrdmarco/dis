<?php

namespace Tests\Feature;

use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardSession;
use App\Services\WallboardSessionService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WallboardSessionSecurityTest extends TestCase
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
            'dis.wallboards.rotation_hours' => 1,
            'dis.wallboards.rotation_grace_seconds' => 120,
            'dis.wallboards.credential_cookie_days' => 365,
            'dis.wallboards.touch_interval_seconds' => 10,
        ]);
    }

    public function test_revoked_legacy_expired_and_disabled_sessions_are_rejected(): void
    {
        $wallboard = $this->wallboard();

        [$revoked, $revokedCookie] = $this->sessionCredential($wallboard);
        $revoked->forceFill(['revoked_at' => now()])->save();
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $revokedCookie;
        $this->assertWallboardSessionRejected();

        [$idleExpired, $idleExpiredCookie] = $this->sessionCredential($wallboard);
        $idleExpired->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $idleExpiredCookie;
        $this->assertWallboardSessionRejected();

        [$disabledSession, $disabledCookie] = $this->sessionCredential($wallboard);
        $wallboard->forceFill(['is_enabled' => false])->save();
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $disabledCookie;
        $this->assertWallboardSessionRejected();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_permanent_session_remains_valid_beyond_the_former_idle_and_absolute_limits(): void
    {
        $this->travelTo(now()->startOfSecond());
        $wallboard = $this->wallboard();
        [$session, $rawCookie] = $this->sessionCredential($wallboard);
        $session->forceFill([
            'created_at' => now()->subDays(730),
            'last_seen_at' => now()->subDays(60),
            'last_rotated_at' => now(),
            'expires_at' => null,
        ])->saveQuietly();
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $rawCookie;

        $this->browserJson('GET', '/api/wallboard/state')->assertOk();

        $session->refresh();
        $this->assertNull($session->expires_at);
        $this->assertSame(now()->timestamp, $session->last_seen_at?->timestamp);
    }

    public function test_due_session_rotates_with_a_short_previous_credential_grace_window(): void
    {
        $this->travelTo(now()->startOfSecond());
        $wallboard = $this->wallboard();
        [$session, $oldRawCookie, $oldCredential] = $this->sessionCredential($wallboard);
        $session->forceFill(['last_rotated_at' => now()->subHours(2)])->save();
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $oldRawCookie;

        $rotated = $this->browserJson('GET', '/api/wallboard/state')->assertOk();
        $replacement = $rotated->getCookie(WallboardSessionService::COOKIE_NAME, true);
        $this->assertNotNull($replacement);
        $this->assertNotSame($oldCredential, (string) $replacement->getValue());
        $newRawCookie = $this->browserCookies[WallboardSessionService::COOKIE_NAME];

        $session->refresh();
        $this->assertNotNull($session->previous_token_hash);
        $this->assertTrue($session->previous_token_expires_at->isFuture());

        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $oldRawCookie;
        $this->browserJson('GET', '/api/wallboard/state')->assertOk();

        $this->travel(121)->seconds();
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $oldRawCookie;
        $this->assertWallboardSessionRejected();

        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $newRawCookie;
        $this->browserJson('GET', '/api/wallboard/state')->assertOk();
    }

    public function test_rotation_renews_the_persistent_cookie_without_adding_a_server_expiry(): void
    {
        $this->travelTo(now()->startOfSecond());
        $wallboard = $this->wallboard();
        [$session, $rawCookie] = $this->sessionCredential($wallboard);
        $session->forceFill([
            'created_at' => now()->subDays(730),
            'last_seen_at' => now()->subMinute(),
            'last_rotated_at' => now()->subHours(2),
            'expires_at' => null,
        ])->saveQuietly();
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $rawCookie;

        $response = $this->browserJson('GET', '/api/wallboard/state')->assertOk();
        $replacementCookie = $response->getCookie(WallboardSessionService::COOKIE_NAME, false);
        $this->assertNotNull($replacementCookie);
        $this->assertSame(now()->addDays(365)->timestamp, $replacementCookie->getExpiresTime());
        $this->assertNull($session->refresh()->expires_at);
    }

    public function test_pairing_session_immediately_marks_the_wallboard_seen(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-19 10:00:00', 'Europe/Amsterdam'));
        $wallboard = $this->wallboard();
        $request = Request::create('/api/wallboard/pairing/status', 'POST', server: [
            'REMOTE_ADDR' => '192.0.2.61',
            'HTTP_USER_AGENT' => 'DIS wallboard test',
        ]);

        $created = app(WallboardSessionService::class)->createFromPairing(
            $wallboard,
            rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'Crisisruimte',
            $request,
        );

        $this->assertSame('2026-07-19 10:00:00', $created['session']->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertNull($created['session']->expires_at);
        $this->assertSame('2026-07-19 10:00:00', $wallboard->refresh()->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_postgresql_utc_carrier_heartbeat_is_touched_and_legacy_expiry_stays_fail_closed(): void
    {
        config()->set('app.timezone', 'Europe/Amsterdam');
        $this->travelTo(CarbonImmutable::parse('2026-07-19 10:00:00', 'Europe/Amsterdam'));
        $carrierSession = (new WallboardSession)->newFromBuilder([
            'last_seen_at' => '2026-07-19 10:00:00.000000+00',
            'expires_at' => '2026-07-19 10:00:10.000000+00',
        ]);
        $service = app(WallboardSessionService::class);
        $touchDue = new \ReflectionMethod($service, 'heartbeatTouchDue');
        $expired = new \ReflectionMethod($service, 'hasExpired');
        $this->assertTrue($touchDue->invoke($service, $carrierSession, now()->addSeconds(10)));
        $this->assertTrue($expired->invoke($service, $carrierSession->expires_at, now()->addSeconds(11)));
        $this->assertFalse($expired->invoke($service, null, now()->addYears(10)));

        $this->travelTo(CarbonImmutable::parse('2026-01-19 10:00:00', 'Europe/Amsterdam'));
        $winterCarrierSession = (new WallboardSession)->newFromBuilder([
            'last_seen_at' => '2026-01-19 10:00:00.000000+00',
        ]);
        $this->assertTrue($touchDue->invoke($service, $winterCarrierSession, now()->addSeconds(10)));

        $this->travelTo(CarbonImmutable::parse('2026-07-19 10:00:00', 'Europe/Amsterdam'));

        $wallboard = $this->wallboard();
        [$session, $rawCookie] = $this->sessionCredential($wallboard);
        DB::table('wallboard_sessions')->where('id', $session->id)->update([
            'created_at' => '2026-07-19 08:00:00.000000+00',
            'updated_at' => '2026-07-19 08:00:00.000000+00',
            'last_seen_at' => '2026-07-19 08:00:00.000000+00',
            'last_rotated_at' => '2026-07-19 08:00:00.000000+00',
            'expires_at' => '2026-07-20 08:00:00.000000+00',
        ]);
        $wallboard->forceFill(['last_seen_at' => null])->save();
        $this->travel(11)->seconds();
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $rawCookie;

        $this->browserJson('GET', '/api/wallboard/control')->assertOk();

        $this->assertSame('2026-07-19 10:00:11', $session->refresh()->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertNull($session->expires_at);
        $this->assertSame('2026-07-19 10:00:11', $wallboard->refresh()->last_seen_at->format('Y-m-d H:i:s'));

        [$expiredSession, $expiredCookie] = $this->sessionCredential($wallboard);
        DB::table('wallboard_sessions')->where('id', $expiredSession->id)->update([
            'created_at' => '2026-07-19 08:00:00.000000+00',
            'updated_at' => '2026-07-19 08:00:00.000000+00',
            'last_seen_at' => '2026-07-19 08:00:00.000000+00',
            'last_rotated_at' => '2026-07-19 08:00:00.000000+00',
            'expires_at' => '2026-07-19 08:00:10.000000+00',
        ]);
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $expiredCookie;

        $this->assertWallboardSessionRejected();
    }

    public function test_state_is_no_store_and_contains_only_current_consented_operational_map_data(): void
    {
        $this->travelTo(now()->startOfSecond());
        $wallboard = $this->wallboard([
            'map' => [
                'show_active_incidents' => true,
                'show_test_incidents' => false,
                'show_live_locations' => true,
                'show_routes' => false,
                'show_command_centers' => false,
                'show_historical_incidents' => false,
            ],
        ]);
        [, $cookie] = $this->sessionCredential($wallboard);
        $this->browserCookies[WallboardSessionService::COOKIE_NAME] = $cookie;

        $pilot = $this->pilot('visible-pilot@example.test', 'Actuele piloot', 'GEHEIM-WOONADRES');
        $stalePilot = $this->pilot('stale-pilot@example.test', 'Verlopen piloot', 'NOG-EEN-GEHEIM');
        $incident = $this->incident($pilot, 'WALLBOARD-001', false, 'active');
        $this->incident($pilot, 'WALLBOARD-DRAFT', false, 'draft');
        $this->incident($pilot, 'WALLBOARD-TEST', true, 'active');
        $dispatch = $this->sentDispatch($incident, $pilot);
        $this->acceptedRecipient($dispatch, $pilot);
        $this->acceptedRecipient($dispatch, $stalePilot);
        $consent = $this->consent($incident, $pilot);
        $staleConsent = $this->consent($incident, $stalePilot);
        $this->location($incident, $pilot, $consent, now());
        $this->location($incident, $stalePilot, $staleConsent, now()->subMinutes(6));

        $state = $this->browserJson('GET', '/api/wallboard/state')
            ->assertOk()
            ->assertJsonCount(1, 'data.map.incidents')
            ->assertJsonPath('data.map.incidents.0.reference', 'WALLBOARD-001')
            ->assertJsonCount(1, 'data.map.live_locations')
            ->assertJsonPath('data.map.live_locations.0.user.id', $pilot->id)
            ->assertJsonPath('data.map.live_locations.0.user.name', $pilot->name)
            ->assertJsonPath('data.map.live_locations.0.location_is_current', true)
            ->assertJsonPath('data.map.live_locations.0.route', null);

        $this->assertStringContainsString('no-store', (string) $state->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $state->headers->get('Cache-Control'));
        $payload = $state->getContent();
        foreach ([
            'REPORTER-GEHEIM',
            '0612345678',
            'INTERNE-NOTITIE',
            'CUSTOM-GEHEIM',
            $pilot->email,
            'GEHEIM-WOONADRES',
            'NOG-EEN-GEHEIM',
            'WALLBOARD-DRAFT',
            'WALLBOARD-TEST',
        ] as $forbiddenValue) {
            $this->assertStringNotContainsString($forbiddenValue, $payload);
        }
        $this->assertSame(
            ['id', 'name'],
            array_keys((array) $state->json('data.map.live_locations.0.user')),
        );

        $consent->forceFill([
            'is_active' => false,
            'state_version' => (int) $consent->state_version + 1,
            'revoked_at' => now(),
        ])->save();
        $this->browserJson('GET', '/api/wallboard/state')
            ->assertOk()
            ->assertJsonCount(0, 'data.map.live_locations');
    }

    /** @param array<string, mixed> $configuration */
    private function wallboard(array $configuration = []): Wallboard
    {
        return Wallboard::query()->create([
            'name' => 'Veilig testwallboard',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::normalize($configuration, [
                'map' => [
                    'show_active_incidents' => false,
                    'show_live_locations' => false,
                    'show_routes' => false,
                    'show_command_centers' => false,
                    'show_historical_incidents' => false,
                ],
            ]),
            'is_enabled' => true,
        ]);
    }

    /** @return array{0: WallboardSession, 1: string, 2: string} */
    private function sessionCredential(Wallboard $wallboard): array
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash_hmac('sha256', $secret, (string) config('app.key')),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => null,
        ]);
        $credential = $session->id.'.'.$secret;

        return [$session, $this->encryptCookie($credential), $credential];
    }

    private function encryptCookie(string $value): string
    {
        $name = WallboardSessionService::COOKIE_NAME;

        return encrypt(
            CookieValuePrefix::create($name, app('encrypter')->getKey()).$value,
            false,
        );
    }

    private function assertWallboardSessionRejected(): void
    {
        $this->browserJson('GET', '/api/wallboard/state')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'wallboard_unauthenticated')
            ->assertCookieExpired(WallboardSessionService::COOKIE_NAME);
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
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

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
            'REMOTE_ADDR' => '192.0.2.61',
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

    private function pilot(string $email, string $name, string $homeCity): User
    {
        return User::query()->create([
            'name' => $name,
            'first_name' => $name,
            'last_name' => 'Testpiloot',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'phone_number' => '0699999999',
            'home_city' => $homeCity,
            'home_region' => 'Geheime regio',
            'home_country' => 'NL',
            'home_latitude' => 52.12,
            'home_longitude' => 5.12,
            'account_status' => 'active',
        ]);
    }

    private function incident(User $creator, string $reference, bool $isTest, string $status): Incident
    {
        return Incident::query()->create([
            'reference' => $reference,
            'title' => 'Operationeel incident',
            'description' => 'Publieke operationele omschrijving',
            'internal_notes' => 'INTERNE-NOTITIE',
            'reporter_name' => 'REPORTER-GEHEIM',
            'reporter_phone' => '0612345678',
            'custom_fields' => ['secret' => 'CUSTOM-GEHEIM'],
            'priority' => 'normal',
            'status' => $status,
            'is_test' => $isTest,
            'location_label' => 'Veilige kaartlocatie',
            'latitude' => 52.30,
            'longitude' => 5.30,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
        ]);
    }

    private function sentDispatch(Incident $incident, User $creator): DispatchRequest
    {
        return DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $creator->id,
            'requested_by_name' => $creator->name,
            'requested_by_email' => $creator->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Testmelding',
            'sent_at' => now(),
        ]);
    }

    private function acceptedRecipient(DispatchRequest $dispatch, User $pilot): void
    {
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'response_status' => 'accepted',
            'responded_at' => now(),
            'notified_at' => now(),
        ]);
    }

    private function consent(Incident $incident, User $pilot): LocationSharingConsent
    {
        return LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now()->subMinute(),
        ])->refresh();
    }

    private function location(
        Incident $incident,
        User $pilot,
        LocationSharingConsent $consent,
        \DateTimeInterface $timestamp,
    ): void {
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'consent_state_version' => $consent->state_version,
            'latitude' => 52.10,
            'longitude' => 5.10,
            'accuracy_meters' => 8,
            'recorded_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
    }
}
