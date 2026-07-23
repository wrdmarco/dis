<?php

namespace Tests\Feature;

use App\Contracts\OperationalRadarProvider;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Models\WallboardSession;
use App\Services\WallboardSessionService;
use App\Support\OperationalRadarContent;
use App\Support\WallboardConfiguration;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WallboardWeatherRadarTest extends TestCase
{
    use RefreshDatabase;

    private WallboardWeatherRadarProviderStub $radar;

    private ?string $radarFixturePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->radar = new WallboardWeatherRadarProviderStub;
        $this->app->instance(OperationalRadarProvider::class, $this->radar);
    }

    protected function tearDown(): void
    {
        if ($this->radarFixturePath !== null) {
            File::delete($this->radarFixturePath);
        }

        parent::tearDown();
    }

    public function test_weather_radar_configuration_has_a_canonical_kind_and_rejects_unknown_kinds(): void
    {
        $default = WallboardConfiguration::normalize([
            'pages' => [$this->page('radar', 'Weerradar', 'weather_radar')],
        ]);
        $this->assertSame(
            WallboardConfiguration::DEFAULT_WEATHER_RADAR_KIND,
            $default['pages'][0]['options']['radar_kind'],
        );

        $lightning = WallboardConfiguration::normalize([
            'pages' => [$this->page('radar', 'Bliksemradar', 'weather_radar', [
                'radar_kind' => 'lightning',
            ])],
        ]);
        $this->assertSame('lightning', $lightning['pages'][0]['options']['radar_kind']);

        try {
            WallboardConfiguration::normalize([
                'pages' => [$this->page('radar', 'Onbekende radar', 'weather_radar', [
                    'radar_kind' => 'external',
                ])],
            ]);
            $this->fail('An unsupported weather radar kind was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'configuration.pages.0.options.radar_kind',
                $exception->errors(),
            );
        }
    }

    public function test_paired_state_and_live_load_radar_lazily_and_only_rewrite_validated_atlas_urls(): void
    {
        $actor = $this->user('wallboard-radar-state@example.test');
        $wallboard = $this->wallboard($actor, $this->configuration([
            $this->page('radar', 'Weerradar', 'weather_radar'),
        ]));
        $cookie = $this->wallboardCredential($wallboard);
        $this->radar->metadata['lightning']['atlas_url'] = 'https://untrusted.example/radar.png';

        $this->wallboardGetJson('/api/wallboard/state', $cookie)
            ->assertOk()
            ->assertJsonPath(
                'data.weather_radar.precipitation.atlas_url',
                '/api/wallboard/weather-radar/precipitation/'.
                    WallboardWeatherRadarProviderStub::PRECIPITATION_SNAPSHOT.'.png',
            )
            ->assertJsonPath('data.weather_radar.precipitation.age_seconds', 60)
            ->assertJsonPath('data.weather_radar.precipitation.lag_seconds', 30)
            ->assertJsonPath('data.weather_radar.precipitation.observed_period_end', null)
            ->assertJsonPath('data.weather_radar.lightning.atlas_url', null);
        $this->assertSame(1, $this->radar->metadataCalls);

        $this->wallboardGetJson('/api/wallboard/live', $cookie)
            ->assertOk()
            ->assertJsonPath(
                'data.weather_radar.precipitation.atlas_url',
                '/api/wallboard/weather-radar/precipitation/'.
                    WallboardWeatherRadarProviderStub::PRECIPITATION_SNAPSHOT.'.png',
            )
            ->assertJsonPath(
                'data.weather_radar.lightning.observed_period_end',
                '2026-07-23T10:35:00+00:00',
            )
            ->assertJsonPath('data.weather_radar.lightning.atlas_url', null);
        $this->assertSame(2, $this->radar->metadataCalls);

        $wallboard->forceFill([
            'configuration' => $this->configuration([
                $this->page('map', 'Kaart', 'map'),
            ]),
        ])->save();

        $this->wallboardGetJson('/api/wallboard/state', $cookie)
            ->assertOk()
            ->assertJsonPath('data.weather_radar', null);
        $this->wallboardGetJson('/api/wallboard/live', $cookie)
            ->assertOk()
            ->assertJsonPath('data.weather_radar', null);
        $this->assertSame(2, $this->radar->metadataCalls);
    }

    public function test_wallboard_radar_atlas_requires_a_paired_session_and_reuses_validated_provider_content(): void
    {
        $actor = $this->user('wallboard-radar-atlas@example.test');
        $wallboard = $this->wallboard($actor, $this->configuration([
            $this->page('radar', 'Weerradar', 'weather_radar'),
        ]));
        $cookie = $this->wallboardCredential($wallboard);
        $snapshot = WallboardWeatherRadarProviderStub::PRECIPITATION_SNAPSHOT;
        $url = '/api/wallboard/weather-radar/precipitation/'.$snapshot.'.png';
        $png = "\x89PNG\r\n\x1a\nwallboard-radar";
        $this->radarFixturePath = storage_path('framework/testing/wallboard-weather-radar.png');
        File::ensureDirectoryExists(dirname($this->radarFixturePath));
        File::put($this->radarFixturePath, $png);
        $sha256 = hash('sha256', $png);
        $this->radar->files['precipitation|'.$snapshot] = new OperationalRadarContent(
            $this->radarFixturePath,
            strlen($png),
            $sha256,
        );

        $this->get($url)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'wallboard_unauthenticated');
        $this->assertSame(0, $this->radar->fileCalls);

        $response = $this->wallboardGet($url, $cookie)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Length', (string) strlen($png))
            ->assertHeader('ETag', '"'.$sha256.'"')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
        $this->assertSame($png, $response->streamedContent());
        $this->assertSame(1, $this->radar->fileCalls);

        $this->wallboardGet(
            '/api/wallboard/weather-radar/unknown/'.$snapshot.'.png',
            $cookie,
        )->assertNotFound();
        $this->wallboardGet(
            '/api/wallboard/weather-radar/lightning/not-a-snapshot.png',
            $cookie,
        )->assertNotFound();
        $this->assertSame(1, $this->radar->fileCalls);
    }

    public function test_live_admin_preview_keeps_the_existing_operational_atlas_url(): void
    {
        $manager = $this->user(
            'wallboard-radar-preview@example.test',
            ['wallboards.manage'],
        );
        $playlist = $this->playlist($manager, WallboardPlaylist::DATA_MODE_LIVE);

        $this->asWebClient($manager)
            ->postJson('/api/admin/wallboard-playlists/'.$playlist->id.'/preview-state', [
                'configuration' => [
                    'pages' => [$this->page('radar', 'Bliksemradar', 'weather_radar', [
                        'radar_kind' => 'lightning',
                    ])],
                ],
            ])
            ->assertOk()
            ->assertJsonPath(
                'data.weather_radar.precipitation.atlas_url',
                '/api/operational-weather/radar/precipitation/'.
                    WallboardWeatherRadarProviderStub::PRECIPITATION_SNAPSHOT.'.png',
            )
            ->assertJsonPath(
                'data.weather_radar.lightning.atlas_url',
                '/api/operational-weather/radar/lightning/'.
                    WallboardWeatherRadarProviderStub::LIGHTNING_SNAPSHOT.'.png',
            )
            ->assertJsonPath(
                'data.wallboard.configuration.pages.0.options.radar_kind',
                'lightning',
            );
        $this->assertSame(1, $this->radar->metadataCalls);
    }

    public function test_atlas_uses_the_current_live_alarm_playlist_and_requires_the_matching_kind(): void
    {
        $actor = $this->user('wallboard-radar-alarm-playlist@example.test');
        $alarmPlaylist = $this->playlist(
            $actor,
            WallboardPlaylist::DATA_MODE_LIVE,
            $this->configuration([
                $this->page('lightning', 'Bliksemradar', 'weather_radar', [
                    'radar_kind' => 'lightning',
                ]),
            ]),
            WallboardPlaylist::PURPOSE_ALARM,
        );
        $wallboard = $this->wallboard(
            $actor,
            $this->configuration([$this->page('map', 'Kaart', 'map')]),
            activeIncidentPlaylist: $alarmPlaylist,
        );
        $this->incident($actor);
        $cookie = $this->wallboardCredential($wallboard);
        $png = "\x89PNG\r\n\x1a\nalarm-lightning-radar";
        $this->radarFixturePath = storage_path('framework/testing/wallboard-alarm-weather-radar.png');
        File::ensureDirectoryExists(dirname($this->radarFixturePath));
        File::put($this->radarFixturePath, $png);
        $snapshot = WallboardWeatherRadarProviderStub::LIGHTNING_SNAPSHOT;
        $this->radar->files['lightning|'.$snapshot] = new OperationalRadarContent(
            $this->radarFixturePath,
            strlen($png),
            hash('sha256', $png),
        );

        $this->wallboardGet(
            '/api/wallboard/weather-radar/lightning/'.$snapshot.'.png',
            $cookie,
        )->assertOk();
        $this->assertSame(1, $this->radar->fileCalls);

        $this->wallboardGet(
            '/api/wallboard/weather-radar/precipitation/'.
                WallboardWeatherRadarProviderStub::PRECIPITATION_SNAPSHOT.'.png',
            $cookie,
        )->assertNotFound();
        $this->assertSame(1, $this->radar->fileCalls);
    }

    public function test_normal_playlist_atlas_remains_authorized_when_alarm_starts_after_metadata(): void
    {
        $actor = $this->user('wallboard-radar-alarm-start@example.test');
        $normalPlaylist = $this->playlist(
            $actor,
            WallboardPlaylist::DATA_MODE_LIVE,
            $this->configuration([
                $this->page('precipitation', 'Buienradar', 'weather_radar'),
            ]),
        );
        $alarmPlaylist = $this->playlist(
            $actor,
            WallboardPlaylist::DATA_MODE_LIVE,
            $this->configuration([
                $this->page('lightning', 'Bliksemradar', 'weather_radar', [
                    'radar_kind' => 'lightning',
                ]),
            ]),
            WallboardPlaylist::PURPOSE_ALARM,
        );
        $wallboard = $this->wallboard(
            $actor,
            WallboardConfiguration::defaults(),
            $normalPlaylist,
            $alarmPlaylist,
        );
        $cookie = $this->wallboardCredential($wallboard);
        $url = $this->registerRadarFile(
            'precipitation',
            WallboardWeatherRadarProviderStub::PRECIPITATION_SNAPSHOT,
            'alarm-start',
        );

        $this->wallboardGetJson('/api/wallboard/state', $cookie)
            ->assertOk()
            ->assertJsonPath('data.weather_radar.precipitation.atlas_url', $url);

        $this->incident($actor);

        $this->wallboardGet($url, $cookie)->assertOk();
        $this->assertSame(1, $this->radar->fileCalls);
    }

    public function test_alarm_playlist_atlas_remains_authorized_when_alarm_ends_after_metadata(): void
    {
        $actor = $this->user('wallboard-radar-alarm-end@example.test');
        $normalPlaylist = $this->playlist(
            $actor,
            WallboardPlaylist::DATA_MODE_LIVE,
            $this->configuration([
                $this->page('precipitation', 'Buienradar', 'weather_radar'),
            ]),
        );
        $alarmPlaylist = $this->playlist(
            $actor,
            WallboardPlaylist::DATA_MODE_LIVE,
            $this->configuration([
                $this->page('lightning', 'Bliksemradar', 'weather_radar', [
                    'radar_kind' => 'lightning',
                ]),
            ]),
            WallboardPlaylist::PURPOSE_ALARM,
        );
        $wallboard = $this->wallboard(
            $actor,
            WallboardConfiguration::defaults(),
            $normalPlaylist,
            $alarmPlaylist,
        );
        $incident = $this->incident($actor);
        $cookie = $this->wallboardCredential($wallboard);
        $url = $this->registerRadarFile(
            'lightning',
            WallboardWeatherRadarProviderStub::LIGHTNING_SNAPSHOT,
            'alarm-end',
        );

        $this->wallboardGetJson('/api/wallboard/live', $cookie)
            ->assertOk()
            ->assertJsonPath('data.weather_radar.lightning.atlas_url', $url);

        $incident->forceFill([
            'status' => 'resolved',
            'closed_at' => now(),
        ])->save();

        $this->wallboardGet($url, $cookie)->assertOk();
        $this->assertSame(1, $this->radar->fileCalls);
    }

    public function test_unassigned_live_playlist_never_authorizes_its_radar_atlas(): void
    {
        $actor = $this->user('wallboard-radar-unassigned@example.test');
        $this->playlist(
            $actor,
            WallboardPlaylist::DATA_MODE_LIVE,
            $this->configuration([
                $this->page('lightning', 'Bliksemradar', 'weather_radar', [
                    'radar_kind' => 'lightning',
                ]),
            ]),
            WallboardPlaylist::PURPOSE_ALARM,
        );
        $wallboard = $this->wallboard(
            $actor,
            $this->configuration([$this->page('map', 'Kaart', 'map')]),
        );
        $cookie = $this->wallboardCredential($wallboard);
        $url = $this->registerRadarFile(
            'lightning',
            WallboardWeatherRadarProviderStub::LIGHTNING_SNAPSHOT,
            'unassigned',
        );

        $this->wallboardGet($url, $cookie)->assertNotFound();
        $this->assertSame(0, $this->radar->fileCalls);
    }

    public function test_demo_preview_state_live_and_atlas_never_call_the_radar_provider(): void
    {
        $manager = $this->user(
            'wallboard-radar-demo@example.test',
            ['wallboards.manage'],
        );
        $configuration = $this->configuration([
            $this->page('radar', 'Weerradar', 'weather_radar'),
        ]);
        $playlist = $this->playlist(
            $manager,
            WallboardPlaylist::DATA_MODE_DEMO,
            $configuration,
        );
        $alarmPlaylist = $this->playlist(
            $manager,
            WallboardPlaylist::DATA_MODE_LIVE,
            $configuration,
            WallboardPlaylist::PURPOSE_ALARM,
        );

        $this->asWebClient($manager)
            ->postJson('/api/admin/wallboard-playlists/'.$playlist->id.'/preview-state', [
                'data_mode' => WallboardPlaylist::DATA_MODE_DEMO,
                'configuration' => $configuration,
            ])
            ->assertOk()
            ->assertJsonPath('data.weather_radar', null);

        $wallboard = $this->wallboard(
            $manager,
            WallboardConfiguration::defaults(),
            $playlist,
            $alarmPlaylist,
        );
        $this->incident($manager);
        $cookie = $this->wallboardCredential($wallboard);
        $this->wallboardGetJson('/api/wallboard/state', $cookie)
            ->assertOk()
            ->assertJsonPath('data.weather_radar', null);
        $this->wallboardGetJson('/api/wallboard/live', $cookie)
            ->assertOk()
            ->assertJsonPath('data.weather_radar', null);
        $this->wallboardGet(
            '/api/wallboard/weather-radar/precipitation/'.
                WallboardWeatherRadarProviderStub::PRECIPITATION_SNAPSHOT.'.png',
            $cookie,
        )->assertNotFound();

        $this->assertSame(0, $this->radar->metadataCalls);
        $this->assertSame(0, $this->radar->fileCalls);
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function configuration(array $pages): array
    {
        return WallboardConfiguration::normalize(['pages' => $pages]);
    }

    /**
     * @param  array<string, mixed>  $options
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

    private function playlist(
        User $actor,
        string $dataMode,
        ?array $configuration = null,
        string $purpose = WallboardPlaylist::PURPOSE_NORMAL,
    ): WallboardPlaylist {
        return WallboardPlaylist::query()->create([
            'name' => 'Weerradarplaylist',
            'data_mode' => $dataMode,
            'purpose' => $purpose,
            'configuration' => $configuration ?? WallboardConfiguration::defaults(),
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @param array<string, mixed> $configuration */
    private function wallboard(
        User $actor,
        array $configuration,
        ?WallboardPlaylist $playlist = null,
        ?WallboardPlaylist $activeIncidentPlaylist = null,
    ): Wallboard {
        return Wallboard::query()->create([
            'name' => 'Weerradarwallboard',
            'playlist_id' => $playlist?->id,
            'active_incident_playlist_id' => $activeIncidentPlaylist?->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'display_profile' => Wallboard::DISPLAY_PROFILE_AUTO,
            'configuration' => $configuration,
            'config_version' => 1,
            'control_version' => 1,
            'refresh_version' => 1,
            'rotation_started_at' => now(),
            'is_enabled' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function incident(User $actor): Incident
    {
        return Incident::query()->create([
            'reference' => 'RADAR-'.str()->upper((string) str()->random(8)),
            'title' => 'Actieve inzet voor weerradar',
            'priority' => 'normal',
            'status' => 'in_progress',
            'is_test' => false,
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
            'created_by_email' => $actor->email,
            'opened_at' => now(),
        ]);
    }

    private function registerRadarFile(string $kind, string $snapshot, string $suffix): string
    {
        $png = "\x89PNG\r\n\x1a\nwallboard-radar-".$suffix;
        $this->radarFixturePath = storage_path(
            'framework/testing/wallboard-weather-radar-'.$suffix.'.png',
        );
        File::ensureDirectoryExists(dirname($this->radarFixturePath));
        File::put($this->radarFixturePath, $png);
        $this->radar->files[$kind.'|'.$snapshot] = new OperationalRadarContent(
            $this->radarFixturePath,
            strlen($png),
            hash('sha256', $png),
        );

        return '/api/wallboard/weather-radar/'.$kind.'/'.$snapshot.'.png';
    }

    private function wallboardCredential(Wallboard $wallboard): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash_hmac('sha256', $secret, (string) config('app.key')),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        return $session->id.'.'.$secret;
    }

    private function wallboardGetJson(string $uri, string $cookie): TestResponse
    {
        return $this->wallboardRequest($uri, $cookie, true);
    }

    private function wallboardGet(string $uri, string $cookie): TestResponse
    {
        return $this->wallboardRequest($uri, $cookie, false);
    }

    private function wallboardRequest(string $uri, string $cookie, bool $json): TestResponse
    {
        Auth::forgetGuards();
        $this->withoutMiddleware(EncryptCookies::class);
        $client = $this->disableCookieEncryption()
            ->withUnencryptedCookie(WallboardSessionService::COOKIE_NAME, $cookie)
            ->withCredentials()
            ->withHeaders(['Origin' => 'https://dis.example.test']);

        return $json ? $client->getJson($uri) : $client->get($uri);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'Wallboard Weather Radar User',
            'first_name' => 'Wallboard',
            'last_name' => 'Weather Radar User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-weather-radar-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Wallboard weather radar role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'Test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken(
            'Wallboard weather radar web test',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withToken($token);
    }
}

final class WallboardWeatherRadarProviderStub implements OperationalRadarProvider
{
    public const PRECIPITATION_SNAPSHOT = '20260723T103000Z-0123456789abcdef';

    public const LIGHTNING_SNAPSHOT = '20260723T102500Z-fedcba9876543210';

    public int $metadataCalls = 0;

    public int $fileCalls = 0;

    /** @var array<string, mixed> */
    public array $metadata;

    /** @var array<string, OperationalRadarContent> */
    public array $files = [];

    public function __construct()
    {
        $this->metadata = [
            'precipitation' => $this->layer(
                'precipitation',
                self::PRECIPITATION_SNAPSHOT,
                5,
                5,
            ),
            'lightning' => $this->layer(
                'lightning',
                self::LIGHTNING_SNAPSHOT,
                4,
                2,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $this->metadataCalls++;

        return $this->metadata;
    }

    public function file(string $kind, string $snapshotId): ?OperationalRadarContent
    {
        $this->fileCalls++;

        return $this->files[$kind.'|'.$snapshotId] ?? null;
    }

    /** @return array<string, mixed> */
    private function layer(string $kind, string $snapshot, int $columns, int $rows): array
    {
        return [
            'status' => 'available',
            'reference_time' => '2026-07-23T10:30:00+00:00',
            'observed_period_end' => $kind === 'lightning'
                ? '2026-07-23T10:35:00+00:00'
                : null,
            'age_seconds' => 60,
            'lag_seconds' => 30,
            'refreshed_at' => '2026-07-23T10:31:00+00:00',
            'atlas_url' => '/api/operational-weather/radar/'.$kind.'/'.$snapshot.'.png',
            'atlas_columns' => $columns,
            'atlas_rows' => $rows,
            'frame_width' => 700,
            'frame_height' => 765,
            'frames' => [[
                'index' => 0,
                'valid_at' => '2026-07-23T10:30:00+00:00',
                'lead_minutes' => 0,
            ]],
            'source' => [
                'name' => $kind === 'precipitation' ? 'KNMI radar' : 'EUMETSAT lightning',
                'url' => 'https://example.test/'.$kind,
                'license' => 'Test license',
            ],
            'availability_note' => null,
        ];
    }
}
