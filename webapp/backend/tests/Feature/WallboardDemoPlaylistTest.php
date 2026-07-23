<?php

namespace Tests\Feature;

use App\Contracts\WallboardContentProvider;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Models\WallboardSession;
use App\Services\WallboardContentSnapshotService;
use App\Services\WallboardPlaylistService;
use App\Services\WallboardService;
use App\Services\WallboardSessionService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class WallboardDemoPlaylistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 14:25:00', 'Europe/Amsterdam'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_playlist_mode_defaults_validates_persists_audits_and_reloads_linked_wallboards(): void
    {
        $manager = $this->user('demo-mode@example.test', ['wallboards.manage']);
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->page('summary', 'Overzicht', 'summary')],
        ]);

        $legacyResponse = $this->asAdminClient($manager)->postJson('/api/admin/wallboard-playlists', [
            'name' => 'Bestaande client',
            'configuration' => $configuration,
        ])->assertCreated()
            ->assertJsonPath('data.data_mode', WallboardPlaylist::DATA_MODE_LIVE);
        $legacy = WallboardPlaylist::query()->findOrFail($legacyResponse->json('data.id'));
        $this->assertSame(WallboardPlaylist::DATA_MODE_LIVE, $legacy->data_mode);

        $this->asAdminClient($manager)->postJson('/api/admin/wallboard-playlists', [
            'name' => 'Ongeldige modus',
            'data_mode' => 'training',
            'configuration' => $configuration,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['data_mode']]]);

        $wallboard = $this->wallboard($legacy, $configuration, [
            'config_version' => 4,
            'control_version' => 7,
        ]);
        $this->asAdminClient($manager)->patchJson('/api/admin/wallboard-playlists/'.$legacy->id, [
            'expected_version' => 1,
            'data_mode' => WallboardPlaylist::DATA_MODE_DEMO,
        ])->assertOk()
            ->assertJsonPath('data.data_mode', WallboardPlaylist::DATA_MODE_DEMO)
            ->assertJsonPath('data.version', 2);

        $this->assertSame(WallboardPlaylist::DATA_MODE_DEMO, $legacy->fresh()->data_mode);
        $this->assertSame(5, $wallboard->fresh()->config_version);
        $this->assertSame(8, $wallboard->fresh()->control_version);
        $audit = AuditLog::query()
            ->where('action', 'wallboard_playlists.updated')
            ->where('target_id', $legacy->id)
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame(WallboardPlaylist::DATA_MODE_LIVE, $audit->metadata['previous_data_mode']);
        $this->assertSame(WallboardPlaylist::DATA_MODE_DEMO, $audit->metadata['data_mode']);
    }

    public function test_demo_rotation_uses_each_ordinary_page_duration_once(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'rotation_enabled' => true,
            'pages' => [
                [
                    'id' => 'demo-one',
                    'name' => 'Demo een',
                    'type' => 'message',
                    'duration_seconds' => 10,
                    'options' => ['body' => 'Eerste gewone demopagina.'],
                ],
                [
                    'id' => 'demo-two',
                    'name' => 'Demo twee',
                    'type' => 'safety_notice',
                    'duration_seconds' => 10,
                    'options' => ['body' => 'Tweede gewone demopagina.'],
                ],
            ],
        ]);
        $playlist = $this->playlist(
            'Demo rotatietiming',
            WallboardPlaylist::DATA_MODE_DEMO,
            $configuration,
        );
        $wallboard = $this->wallboard($playlist, $configuration, [
            'rotation_started_at' => CarbonImmutable::parse(
                '2026-07-20 14:25:00',
                'Europe/Amsterdam',
            ),
        ]);
        $credential = $this->wallboardCredential($wallboard);

        $this->wallboardGet('/api/wallboard/control', $credential)
            ->assertOk()
            ->assertJsonPath('data.display.page_id', 'demo-one')
            ->assertJsonPath('data.display.next_change_at', '2026-07-20T14:25:10+02:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 14:25:09', 'Europe/Amsterdam'));
        $this->wallboardGet('/api/wallboard/control', $credential)
            ->assertOk()
            ->assertJsonPath('data.display.page_id', 'demo-one')
            ->assertJsonPath('data.display.next_change_at', '2026-07-20T14:25:10+02:00');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 14:25:10', 'Europe/Amsterdam'));
        $this->wallboardGet('/api/wallboard/control', $credential)
            ->assertOk()
            ->assertJsonPath('data.display.page_id', 'demo-two')
            ->assertJsonPath('data.display.next_change_at', '2026-07-20T14:25:20+02:00');
    }

    public function test_demo_playlist_cannot_be_or_become_an_active_incident_playlist(): void
    {
        $manager = $this->user('demo-active@example.test', ['wallboards.manage']);
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->page('summary', 'Overzicht', 'summary')],
        ]);
        $base = $this->playlist('Basis', WallboardPlaylist::DATA_MODE_LIVE, $configuration);
        $demo = $this->playlist(
            'Demo',
            WallboardPlaylist::DATA_MODE_DEMO,
            $configuration,
            WallboardPlaylist::PURPOSE_ALARM,
        );

        $this->asAdminClient($manager)->postJson('/api/admin/wallboards', [
            'name' => 'Ongeldig actief scherm',
            'playlist_id' => $base->id,
            'active_incident_playlist_id' => $demo->id,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['active_incident_playlist_id']]]);

        $active = $this->playlist(
            'Actieve inzet',
            WallboardPlaylist::DATA_MODE_LIVE,
            $configuration,
            WallboardPlaylist::PURPOSE_ALARM,
        );
        $wallboard = $this->wallboard($base, $configuration, [
            'active_incident_playlist_id' => $active->id,
        ]);
        $this->asAdminClient($manager)->patchJson('/api/admin/wallboard-playlists/'.$active->id, [
            'expected_version' => 1,
            'data_mode' => WallboardPlaylist::DATA_MODE_DEMO,
        ])->assertUnprocessable()
            ->assertJsonPath(
                'error.details.data_mode.0',
                'Een actieve-inzetplaylist moet live gegevens blijven gebruiken.',
            );

        $this->assertSame(WallboardPlaylist::DATA_MODE_LIVE, $active->fresh()->data_mode);
        $this->assertSame($active->id, $wallboard->fresh()->active_incident_playlist_id);
    }

    public function test_paired_demo_endpoints_use_only_fixtures_and_ignore_real_deployments_and_providers(): void
    {
        $provider = $this->countingProvider();
        $this->app->instance(WallboardContentProvider::class, $provider);
        Http::preventStrayRequests();

        $configuration = $this->completeDemoConfiguration();
        $demo = $this->playlist('Volledige demo', WallboardPlaylist::DATA_MODE_DEMO, $configuration);
        $activeLive = $this->playlist(
            'Echte actieve inzet',
            WallboardPlaylist::DATA_MODE_LIVE,
            $configuration,
            WallboardPlaylist::PURPOSE_ALARM,
        );
        $wallboard = $this->wallboard($demo, $configuration, [
            'active_incident_playlist_id' => $activeLive->id,
        ]);
        $credential = $this->wallboardCredential($wallboard);

        $creator = $this->user('real-sentinel@example.test', []);
        Incident::query()->create([
            'reference' => 'REAL-SECRET-SENTINEL',
            'title' => 'Echte operationele titel die nooit zichtbaar mag zijn',
            'priority' => 'critical',
            'status' => 'in_progress',
            'is_test' => false,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
        ]);
        CalendarEvent::query()->create([
            'title' => 'REAL-CALENDAR-SENTINEL',
            'type' => 'meeting',
            'starts_at' => now()->addHour(),
        ]);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $state = $this->wallboardGet('/api/wallboard/state', $credential)->assertOk()
            ->assertJsonPath('data.wallboard.data_mode', WallboardPlaylist::DATA_MODE_DEMO)
            ->assertJsonPath('data.wallboard.runtime_playlist_id', $demo->id)
            ->assertJsonPath('data.wallboard.active_incident_playlist', false)
            ->assertJsonPath('data.operational_summary.active_alarm', null)
            ->assertJsonPath('data.operational_summary.focus', null)
            ->assertJsonPath('data.map.incidents.0.reference', 'DEMO-2026-0043')
            ->assertJsonPath('data.map.live_locations.0.route.geometry.type', 'LineString')
            ->assertJsonPath('data.news.pages.news.items.0.source_label', 'DIS DEMO')
            ->assertJsonPath(
                'data.forecast.pages.forecast.disclaimer',
                'DEMO: deze fictieve waarden mogen nooit voor een vliegbeslissing worden gebruikt.',
            );
        $forecastMetrics = collect($state->json('data.forecast.pages.forecast.metrics'))->keyBy('key');
        $this->assertSame(
            'DIS_DEMO',
            data_get($forecastMetrics->get('low_cloud_cover_pct'), 'cloud_base_observation.attribution'),
        );
        $this->assertSame(
            'DIS_DEMO',
            data_get($forecastMetrics->get('precipitation_outlook'), 'precipitation_outlook.attribution'),
        );
        $this->assertSame(
            'orange',
            data_get($forecastMetrics->get('precipitation_outlook'), 'precipitation_outlook.radar_status'),
        );
        $this->assertSame(
            'orange',
            data_get(
                $forecastMetrics->get('precipitation_outlook'),
                'precipitation_outlook.third_hour_probability_status',
            ),
        );
        $this->assertSame(
            'DIS_DEMO',
            data_get($forecastMetrics->get('thunderstorm_forecast'), 'thunderstorm_outlook.attribution'),
        );
        $this->assertStringNotContainsString('REAL-SECRET-SENTINEL', (string) $state->getContent());
        $this->assertStringNotContainsString('REAL-CALENDAR-SENTINEL', (string) $state->getContent());
        $this->assertCount(13, $state->json('data.kpi.pages.kpi.metrics.1.segments'));
        $this->assertCount(10, $state->json('data.kpi.pages.kpi.metrics.0.segments'));

        $this->wallboardGet('/api/wallboard/live', $credential)->assertOk()
            ->assertJsonPath('data.operational_summary.active_alarm', null);
        $this->wallboardGet('/api/wallboard/static', $credential)->assertOk()
            ->assertJsonPath('data.wallboard.data_mode', WallboardPlaylist::DATA_MODE_DEMO);
        $news = $this->wallboardGet('/api/wallboard/news', $credential)->assertOk();
        $this->wallboardGet('/api/wallboard/news', $credential, [
            'If-None-Match' => (string) $news->headers->get('ETag'),
        ])->assertNotModified();
        $this->wallboardGet('/api/wallboard/ticker', $credential)->assertOk()
            ->assertJsonPath('data.items.0.source_label', 'DIS DEMO');
        $this->wallboardGet('/api/wallboard/control', $credential)->assertOk()
            ->assertJsonPath('data.data_mode', WallboardPlaylist::DATA_MODE_DEMO)
            ->assertJsonPath('data.active_incident_playlist', false)
            ->assertJsonPath('data.display.incident_active', false)
            ->assertJsonPath('data.focus', null)
            ->assertJsonPath('data.transient_alert', null);
        $this->wallboardGet('/api/wallboard/news-images/'.str_repeat('a', 64), $credential)
            ->assertNotFound();

        $this->assertSame(0, $provider->newsCalls);
        $this->assertSame(0, $provider->tickerCalls);
        Http::assertNothingSent();
        foreach ([
            'incidents',
            'dispatch_requests',
            'dispatch_recipients',
            'availability_statuses',
            'assets',
            'pilot_incident_reports',
            'calendar_events',
            'location_updates',
            'location_sharing_consents',
        ] as $forbiddenTable) {
            $this->assertFalse(
                collect($queries)->contains(static fn (string $sql): bool => str_contains($sql, $forbiddenTable)),
                "Demo endpoint queried operational table {$forbiddenTable}.",
            );
        }
    }

    public function test_demo_preview_and_demo_address_writes_do_not_call_live_sources_but_switching_live_validates(): void
    {
        $provider = $this->countingProvider();
        $this->app->instance(WallboardContentProvider::class, $provider);
        Http::preventStrayRequests();
        $manager = $this->user('demo-preview@example.test', ['wallboards.manage']);
        $configuration = $this->completeDemoConfiguration('Niet bestaand demo-adres 123');

        $playlist = app(WallboardPlaylistService::class)->create([
            'name' => 'Adresdemo',
            'data_mode' => WallboardPlaylist::DATA_MODE_DEMO,
            'configuration' => $configuration,
        ], $manager, Request::create('/test', 'POST'));
        $wallboard = $this->wallboard($playlist, $configuration);
        $updatedConfiguration = $configuration;
        $updatedConfiguration['pages'][5]['options']['location_label'] = 'Nog een fictief demo-adres 456';

        app(WallboardPlaylistService::class)->update($playlist, [
            'expected_version' => 1,
            'configuration' => $updatedConfiguration,
        ], $manager, Request::create('/test', 'PATCH'));
        app(WallboardService::class)->update($wallboard, [
            'expected_config_version' => 2,
            'configuration' => $updatedConfiguration,
        ], $manager, Request::create('/test', 'PATCH'));

        $auditCount = AuditLog::query()->count();
        $this->asAdminClient($manager)->postJson(
            '/api/admin/wallboard-playlists/'.$playlist->id.'/preview-state',
            ['data_mode' => WallboardPlaylist::DATA_MODE_DEMO, 'configuration' => $updatedConfiguration],
        )->assertOk()
            ->assertJsonPath('data.wallboard.data_mode', WallboardPlaylist::DATA_MODE_DEMO)
            ->assertJsonPath('data.news.pages.news.items.0.source_label', 'DIS DEMO')
            ->assertJsonPath('data.operational_summary.active_alarm', null);
        $this->assertSame($auditCount, AuditLog::query()->count(), 'Preview must remain read-only.');
        $this->assertSame(0, $provider->newsCalls);
        $this->assertSame(0, $provider->tickerCalls);
        Http::assertNothingSent();

        $label = (string) $updatedConfiguration['pages'][5]['options']['location_label'];
        Cache::put(
            'wallboard:uav-forecast:geocode:'.sha1(mb_strtolower($label)),
            ['resolved' => false],
            900,
        );
        try {
            app(WallboardPlaylistService::class)->update($playlist->fresh(), [
                'expected_version' => 3,
                'data_mode' => WallboardPlaylist::DATA_MODE_LIVE,
            ], $manager, Request::create('/test', 'PATCH'));
            $this->fail('Een onoplosbaar demoadres had niet live mogen worden gezet.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.pages.5.options.location_label', $exception->errors());
        }
        $this->assertSame(WallboardPlaylist::DATA_MODE_DEMO, $playlist->fresh()->data_mode);
        Http::assertNothingSent();
    }

    public function test_legacy_configuration_update_retries_when_demo_playlist_becomes_live_after_precheck(): void
    {
        Http::preventStrayRequests();
        $manager = $this->user('demo-race@example.test', ['wallboards.manage']);
        $configuration = $this->completeDemoConfiguration('Fictief race-adres 123');
        $playlist = $this->playlist('Demo tijdens voorcontrole', WallboardPlaylist::DATA_MODE_DEMO, $configuration);
        $wallboard = $this->wallboard($playlist, $configuration)->load('playlist');
        $updatedConfiguration = $configuration;
        $updatedConfiguration['pages'][5]['options']['location_label'] = 'Onoplosbaar race-adres 456';

        WallboardPlaylist::query()->whereKey($playlist->id)->update([
            'data_mode' => WallboardPlaylist::DATA_MODE_LIVE,
        ]);

        try {
            app(WallboardService::class)->update($wallboard, [
                'configuration' => $updatedConfiguration,
            ], $manager, Request::create('/test', 'PATCH'));
            $this->fail('Een moduswijziging tussen voorcontrole en lock had een conflict moeten geven.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('data mode changed', $exception->getMessage());
        }

        $this->assertSame(
            'Fictief race-adres 123',
            $playlist->fresh()->configuration['pages'][5]['options']['location_label'],
        );
        Http::assertNothingSent();
    }

    public function test_mode_changes_invalidate_live_snapshots_and_etags(): void
    {
        $provider = $this->countingProvider();
        $this->app->instance(WallboardContentProvider::class, $provider);
        $manager = $this->user('demo-cache@example.test', ['wallboards.manage']);
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->page('news', 'Nieuws', 'news', ['sources' => ['ndt'], 'max_items' => 3])],
        ]);
        $playlist = $this->playlist('Cachemodus', WallboardPlaylist::DATA_MODE_LIVE, $configuration);
        $wallboard = $this->wallboard($playlist, $configuration);
        $credential = $this->wallboardCredential($wallboard);
        app(WallboardContentSnapshotService::class)->refreshPlaylist($playlist);

        $live = $this->wallboardGet('/api/wallboard/news', $credential)->assertOk();
        $this->assertStringContainsString('LIVE-SNAPSHOT-SENTINEL', (string) $live->getContent());
        $liveEtag = (string) $live->headers->get('ETag');

        app(WallboardPlaylistService::class)->update($playlist, [
            'expected_version' => 1,
            'data_mode' => WallboardPlaylist::DATA_MODE_DEMO,
        ], $manager, Request::create('/test', 'PATCH'));
        $demo = $this->wallboardGet('/api/wallboard/news', $credential, ['If-None-Match' => $liveEtag])
            ->assertOk();
        $this->assertStringNotContainsString('LIVE-SNAPSHOT-SENTINEL', (string) $demo->getContent());
        $this->assertNotSame($liveEtag, (string) $demo->headers->get('ETag'));
        $this->assertDatabaseMissing('wallboard_content_snapshots', ['playlist_id' => $playlist->id]);

        $provider->newsTitle = 'NEW-LIVE-SNAPSHOT';
        app(WallboardPlaylistService::class)->update($playlist->fresh(), [
            'expected_version' => 2,
            'data_mode' => WallboardPlaylist::DATA_MODE_LIVE,
        ], $manager, Request::create('/test', 'PATCH'));
        $restored = $this->wallboardGet('/api/wallboard/news', $credential)->assertOk();
        $this->assertStringContainsString('NEW-LIVE-SNAPSHOT', (string) $restored->getContent());
        $this->assertStringNotContainsString('LIVE-SNAPSHOT-SENTINEL', (string) $restored->getContent());
    }

    /** @return object&WallboardContentProvider */
    private function countingProvider(): WallboardContentProvider
    {
        return new class implements WallboardContentProvider
        {
            public int $newsCalls = 0;

            public int $tickerCalls = 0;

            public string $newsTitle = 'LIVE-SNAPSHOT-SENTINEL';

            public function news(array $pages): array
            {
                $this->newsCalls++;
                $result = [];
                foreach ($pages as $page) {
                    if (is_array($page) && ($page['type'] ?? null) === 'news') {
                        $result[(string) $page['id']] = [
                            'items' => [[
                                'id' => 'live-news',
                                'source' => 'ndt',
                                'source_id' => 'ndt',
                                'source_label' => 'Live bron',
                                'title' => $this->newsTitle,
                                'excerpt' => 'Live inhoud',
                                'url' => 'https://example.test/live',
                                'image_url' => null,
                                'published_at' => '2026-07-20T10:00:00+02:00',
                            ]],
                            'fallback_used' => false,
                            'lookback_days' => 7,
                        ];
                    }
                }

                return ['pages' => $result, 'generated_at' => '2026-07-20T10:00:00+02:00'];
            }

            public function ticker(array $configuration): array
            {
                unset($configuration);
                $this->tickerCalls++;

                return ['items' => [[
                    'source_id' => 'live',
                    'source_type' => 'internal',
                    'source_label' => 'Live bron',
                    'text' => 'LIVE-TICKER-SENTINEL',
                ]]];
            }
        };
    }

    /** @return array<string, mixed> */
    private function completeDemoConfiguration(string $address = 'Demo-adres 1'): array
    {
        return WallboardConfiguration::normalize([
            'pages' => [
                $this->page('summary', 'Overzicht', 'summary'),
                $this->page('kpi', 'KPI', 'kpi', [
                    'visible_metrics' => ['drones_flown_distribution', 'incidents_by_province'],
                    'metric_visualizations' => [
                        'drones_flown_distribution' => 'pie',
                        'incidents_by_province' => 'bar',
                    ],
                ]),
                $this->page('calendar', 'Kalender', 'calendar', ['max_items' => 3]),
                $this->page('map', 'Kaart', 'map'),
                $this->page('news', 'Nieuws', 'news', ['sources' => ['ndt'], 'max_items' => 3]),
                $this->page('forecast', 'UAV Forecast', 'uav_forecast', [
                    'location_mode' => 'address',
                    'location_label' => $address,
                ]),
            ],
            'map' => [
                'show_active_incidents' => true,
                'show_live_locations' => true,
                'show_routes' => true,
                'show_command_centers' => true,
                'show_historical_incidents' => true,
                'show_summary' => true,
                'show_incident_list' => true,
            ],
            'ticker' => [
                'enabled' => true,
                'sources' => [[
                    'id' => 'external-live-feed',
                    'type' => 'rss',
                    'label' => 'Live feed',
                    'url' => 'https://example.test/feed.xml',
                ]],
            ],
        ]);
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function page(string $id, string $name, string $type, array $options = []): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'duration_seconds' => 30,
            'options' => $options,
        ];
    }

    /** @param array<string, mixed> $configuration */
    private function playlist(
        string $name,
        string $dataMode,
        array $configuration,
        string $purpose = WallboardPlaylist::PURPOSE_NORMAL,
    ): WallboardPlaylist {
        return WallboardPlaylist::query()->create([
            'name' => $name,
            'data_mode' => $dataMode,
            'purpose' => $purpose,
            'configuration' => $configuration,
            'version' => 1,
        ]);
    }

    /** @param array<string, mixed> $configuration
     * @param  array<string, mixed>  $attributes
     */
    private function wallboard(
        WallboardPlaylist $playlist,
        array $configuration,
        array $attributes = [],
    ): Wallboard {
        return Wallboard::query()->create([
            'name' => 'Demo wallboard',
            'playlist_id' => $playlist->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'display_profile' => Wallboard::DISPLAY_PROFILE_AUTO,
            'configuration' => $configuration,
            'config_version' => 1,
            'control_version' => 1,
            'rotation_started_at' => now()->subMinute(),
            'is_enabled' => true,
            ...$attributes,
        ]);
    }

    private function wallboardCredential(Wallboard $wallboard): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => app(WallboardSessionService::class)->tokenHash($secret),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => null,
        ]);

        return $session->id.'.'.$secret;
    }

    /** @param array<string, string> $headers */
    private function wallboardGet(string $uri, string $credential, array $headers = []): TestResponse
    {
        Auth::forgetGuards();
        $this->withoutMiddleware(EncryptCookies::class);

        return $this->disableCookieEncryption()
            ->withUnencryptedCookie(WallboardSessionService::COOKIE_NAME, $credential)
            ->withCredentials()
            ->withHeaders([
                'Accept' => 'application/json',
                'Origin' => 'https://dis.example.test',
                ...$headers,
            ])
            ->get($uri);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'Demo Playlist Test User',
            'first_name' => 'Demo',
            'last_name' => 'Playlist Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-demo-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Wallboard demo test role',
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
        $token = $user->createToken('Wallboard demo admin test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
