<?php

namespace Tests\Feature;

use App\Contracts\WallboardContentProvider;
use App\Models\AuditLog;
use App\Models\CalendarEvent;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WallboardContentSnapshot;
use App\Models\WallboardMediaAsset;
use App\Models\WallboardMediaAssetUsage;
use App\Models\WallboardMediaPlaylist;
use App\Models\WallboardMediaPlaylistItem;
use App\Models\WallboardMediaPlaylistUsage;
use App\Models\WallboardPlaylist;
use App\Support\WallboardConfiguration;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WallboardPlaylistPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->instance(WallboardContentProvider::class, new class implements WallboardContentProvider
        {
            public function news(array $pages): array
            {
                $result = [];
                foreach ($pages as $page) {
                    if (is_array($page) && ($page['type'] ?? null) === 'news') {
                        $result[(string) $page['id']] = [
                            'items' => [[
                                'id' => 'preview-news',
                                'source' => 'ndt',
                                'source_label' => 'Nationaal Drone Team',
                                'title' => 'Actueel previewbericht',
                                'excerpt' => 'Conceptnieuws',
                                'url' => 'https://example.test/preview-news',
                                'image_url' => '/api/wallboard/news-images/'.str_repeat('a', 64),
                                'published_at' => now()->toIso8601String(),
                            ]],
                            'fallback_used' => false,
                            'lookback_days' => 7,
                        ];
                    }
                }

                return ['pages' => $result, 'generated_at' => now()->toIso8601String()];
            }

            public function ticker(array $configuration): array
            {
                return ['items' => [[
                    'source_id' => 'preview-ticker',
                    'source_type' => 'internal',
                    'source_label' => 'Preview',
                    'text' => 'Actuele ticker',
                ]]];
            }
        });
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_preview_requires_authentication_completed_two_factor_permission_and_valid_configuration(): void
    {
        $manager = $this->user('playlist-preview-manager@example.test', ['wallboards.manage']);
        $playlist = $this->playlist($manager);
        $uri = '/api/admin/wallboard-playlists/'.$playlist->id.'/preview-state';
        $payload = ['configuration' => ['pages' => [$this->page('concept', 'Concept', 'summary')]]];

        $this->postJson($uri, $payload)->assertUnauthorized();

        $unprivileged = $this->user('playlist-preview-denied@example.test', []);
        $this->asAdminClient($unprivileged)->postJson($uri, $payload)->assertForbidden();

        $pendingToken = $manager->createToken(
            'Playlist preview pending 2FA',
            ['2fa:pending', 'client:admin'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($pendingToken)
            ->postJson($uri, $payload)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $client = $this->asAdminClient($manager);
        $client
            ->postJson($uri, ['configuration' => ['pages' => []]])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['configuration.pages']]]);
        $client->postJson($uri, [
            'configuration' => [
                'pages' => [$this->page('missing-video', 'Ontbrekende video', 'video', [
                    'media_asset_id' => strtolower((string) Str::ulid()),
                ])],
            ],
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => [
                'configuration.pages.0.options.media_asset_id',
            ]]]);
    }

    public function test_demo_preview_fills_empty_dynamic_inputs_before_shared_configuration_validation(): void
    {
        $manager = $this->user('playlist-preview-demo@example.test', ['wallboards.manage']);
        $playlist = $this->playlist($manager);
        $configuration = [
            'pages' => [
                $this->page('summary', 'Operationeel overzicht', 'summary'),
                $this->page('incidents', 'Incidenten', 'incident_list'),
                $this->page('map', 'Operationele kaart', 'map'),
                $this->page('kpi', 'KPI', 'kpi'),
                $this->page('calendar', 'Kalender', 'calendar'),
                $this->page('quote', 'Quote van de dag', 'quote', ['quotes' => []]),
                $this->page('forecast', 'UAV Forecast', 'uav_forecast', [
                    'location_mode' => 'address',
                    'location_label' => '',
                ]),
                $this->page('news', 'Drone-nieuws', 'news', [
                    'sources' => [],
                    'custom_sources' => [],
                ]),
            ],
            'ticker' => [
                'enabled' => true,
                'sources' => [[
                    'id' => 'unfinished-demo-ticker',
                    'type' => 'internal',
                    'label' => 'Intern bericht',
                    'text' => '',
                ]],
            ],
        ];

        $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboard-playlists/'.$playlist->id.'/preview-state', [
                'data_mode' => WallboardPlaylist::DATA_MODE_DEMO,
                'configuration' => $configuration,
            ])
            ->assertOk()
            ->assertJsonPath('data.wallboard.data_mode', WallboardPlaylist::DATA_MODE_DEMO)
            ->assertJsonPath(
                'data.wallboard.configuration.pages.5.options.quotes.0.text',
                'Goede voorbereiding geeft elke vlucht een veilige start.',
            )
            ->assertJsonPath('data.wallboard.configuration.pages.5.options.quotes.0.author', 'DIS DEMO')
            ->assertJsonPath('data.wallboard.configuration.pages.7.options.sources.0', 'ndt')
            ->assertJsonPath('data.operational_summary.pilot_availability.available', 12)
            ->assertJsonPath('data.map.incidents.0.reference', 'DEMO-2026-0043')
            ->assertJsonPath('data.news.pages.news.items.0.source_label', 'DIS DEMO')
            ->assertJsonPath('data.ticker.items.0.source_label', 'DIS DEMO')
            ->assertJsonPath('data.forecast.pages.forecast.location.label', 'Demolocatie (fictief)')
            ->assertJsonPath('data.calendar.pages.calendar.items.0.title', 'Demo: operationele briefing')
            ->assertJsonStructure(['data' => ['kpi' => ['pages' => ['kpi' => ['metrics']]]]]);
    }

    public function test_preview_returns_live_concept_state_with_admin_media_urls_without_persisting_or_auditing(): void
    {
        $manager = $this->user('playlist-preview-live@example.test', ['wallboards.manage']);
        $playlist = $this->playlist($manager);
        $image = $this->mediaAsset($manager, WallboardMediaAsset::KIND_IMAGE, 'image/webp', null, 3);
        $video = $this->mediaAsset($manager, WallboardMediaAsset::KIND_VIDEO, 'video/mp4', 24, 7);
        $mediaPlaylist = WallboardMediaPlaylist::query()->create([
            'name' => 'Previewfoto\'s',
            'version' => 4,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
        WallboardMediaPlaylistItem::query()->create([
            'media_playlist_id' => $mediaPlaylist->id,
            'media_asset_id' => $image->id,
            'position' => 0,
        ]);
        CalendarEvent::query()->create([
            'title' => 'Previewbriefing',
            'type' => 'meeting',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
        $incident = Incident::query()->create([
            'reference' => 'PREVIEW-001',
            'title' => 'Actuele preview-inzet',
            'description' => 'Alleen actuele publieke velden horen in de preview.',
            'internal_notes' => 'GEHEIM-PREVIEW',
            'priority' => 'high',
            'status' => 'in_progress',
            'is_test' => false,
            'location_label' => 'Utrecht',
            'latitude' => 52.0907,
            'longitude' => 5.1214,
            'created_by' => $manager->id,
            'created_by_name' => $manager->name,
            'created_by_email' => $manager->email,
            'opened_at' => now()->subMinutes(5),
        ]);
        $savedConfiguration = $playlist->configuration;
        $configuration = [
            'rotation_enabled' => true,
            'pages' => [
                $this->page('concept-summary', 'Conceptsamenvatting', 'summary'),
                $this->page('concept-calendar', 'Conceptkalender', 'calendar', ['max_items' => 2]),
                $this->page('concept-news', 'Conceptnieuws', 'news', [
                    'sources' => ['ndt'],
                    'max_items' => 1,
                    'item_duration_seconds' => 8,
                ]),
                $this->page('concept-photo', 'Conceptfoto\'s', 'photo_carousel', [
                    'media_playlist_id' => strtoupper((string) $mediaPlaylist->id),
                    'item_duration_seconds' => 9,
                ]),
                $this->page('concept-video', 'Conceptvideo', 'video', [
                    'media_asset_id' => strtolower((string) $video->id),
                    'media_asset_version' => 1,
                ]),
            ],
            'incident_override' => ['enabled' => true, 'page_id' => 'concept-summary'],
            'ticker' => [
                'enabled' => true,
                'sources' => [[
                    'id' => 'preview-ticker',
                    'type' => 'internal',
                    'label' => 'Preview',
                    'text' => 'Actuele ticker',
                ]],
            ],
        ];

        $response = $this->asAdminClient($manager)
            ->postJson('/api/admin/wallboard-playlists/'.$playlist->id.'/preview-state', [
                'configuration' => $configuration,
            ])
            ->assertOk()
            ->assertJsonPath('data.wallboard.id', (string) $playlist->id)
            ->assertJsonPath('data.wallboard.configuration.pages.0.name', 'Conceptsamenvatting')
            ->assertJsonPath('data.wallboard.display.incident_active', false)
            ->assertJsonPath('data.operational_summary.active_alarm.id', (string) $incident->id)
            ->assertJsonPath('data.operational_summary.focus', null)
            ->assertJsonPath('data.operational_summary.transient_alert', null)
            ->assertJsonPath('data.maintenance', null)
            ->assertJsonPath('data.news.pages.concept-news.items.0.title', 'Actueel previewbericht')
            ->assertJsonPath(
                'data.news.pages.concept-news.items.0.image_url',
                '/api/admin/wallboard-news-images/'.str_repeat('a', 64),
            )
            ->assertJsonPath('data.ticker.items.0.text', 'Actuele ticker')
            ->assertJsonPath('data.calendar.pages.concept-calendar.items.0.title', 'Previewbriefing')
            ->assertJsonPath('data.map.incidents.0.id', (string) $incident->id)
            ->assertJsonPath('data.media.photo_pages.concept-photo.items.0.id', (string) $image->id)
            ->assertJsonPath(
                'data.media.photo_pages.concept-photo.items.0.image_url',
                '/api/admin/wallboard-media/assets/'.$image->id.'/content?v=3',
            )
            ->assertJsonPath('data.media.photo_pages.concept-photo.total_duration_seconds', 9)
            ->assertJsonPath('data.wallboard.configuration.pages.3.duration_seconds', 9)
            ->assertJsonPath('data.wallboard.configuration.pages.4.options.media_asset_id', (string) $video->id)
            ->assertJsonPath('data.wallboard.configuration.pages.4.options.media_asset_version', 7)
            ->assertJsonPath(
                'data.wallboard.configuration.pages.4.options.url',
                '/api/admin/wallboard-media/assets/'.$video->id.'/content',
            )
            ->assertJsonPath('data.wallboard.configuration.pages.4.options.video_duration_seconds', 24)
            ->assertJsonPath('data.wallboard.configuration.pages.4.duration_seconds', 29)
            ->assertJsonPath('data.forecast.pages', []);

        $this->assertStringNotContainsString('GEHEIM-PREVIEW', $response->getContent());
        $this->assertSame($savedConfiguration, $playlist->refresh()->configuration);
        $this->assertSame(1, $playlist->version);
        $this->assertSame(0, WallboardMediaPlaylistUsage::query()->count());
        $this->assertSame(0, WallboardMediaAssetUsage::query()->count());
        $this->assertSame(0, WallboardContentSnapshot::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_preview_news_image_requires_admin_permission_and_serves_only_a_registered_hash(): void
    {
        $identifier = str_repeat('a', 64);
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        $this->assertIsString($png);
        Cache::put('wallboard:news:image:body:v3:'.$identifier, [
            'version' => 3,
            'body' => $png,
            'content_type' => 'image/png',
        ]);
        $uri = '/api/admin/wallboard-news-images/'.$identifier;

        $this->getJson($uri)->assertUnauthorized();
        $unprivileged = $this->user('playlist-preview-image-denied@example.test', []);
        $this->asAdminClient($unprivileged)->get($uri)->assertForbidden();

        $manager = $this->user('playlist-preview-image-manager@example.test', ['wallboards.manage']);
        $response = $this->asAdminClient($manager)->get($uri);
        $response->assertOk()->assertContent($png);
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->asAdminClient($manager)
            ->get('/api/admin/wallboard-news-images/'.str_repeat('b', 64))
            ->assertNotFound();
        $this->asAdminClient($manager)
            ->get('/api/admin/wallboard-news-images/not-a-hash')
            ->assertNotFound();
    }

    public function test_preview_has_a_dedicated_rate_limit(): void
    {
        $manager = $this->user('playlist-preview-rate@example.test', ['wallboards.manage']);
        $playlist = $this->playlist($manager);
        RateLimiter::for('wallboard-playlist-preview', fn (): Limit => Limit::perMinute(1)->by('preview-test'));
        $client = $this->asAdminClient($manager);
        $uri = '/api/admin/wallboard-playlists/'.$playlist->id.'/preview-state';
        $payload = ['configuration' => ['pages' => [$this->page('preview', 'Preview', 'summary')]]];

        $client->postJson($uri, $payload)->assertOk();
        $client->postJson($uri, $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'rate_limited');
    }

    private function playlist(User $actor): WallboardPlaylist
    {
        return WallboardPlaylist::query()->create([
            'name' => 'Opgeslagen playlist',
            'configuration' => WallboardConfiguration::defaults(),
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
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

    private function mediaAsset(
        User $actor,
        string $kind,
        string $mimeType,
        ?int $duration,
        int $version,
    ): WallboardMediaAsset {
        $id = (string) Str::ulid();

        return WallboardMediaAsset::query()->create([
            'id' => $id,
            'display_name' => $kind === WallboardMediaAsset::KIND_VIDEO ? 'Previewvideo' : 'Previewfoto',
            'original_name' => $kind === WallboardMediaAsset::KIND_VIDEO ? 'preview.mp4' : 'preview.webp',
            'kind' => $kind,
            'storage_path' => 'wallboard-media/objects/'.$id.($kind === WallboardMediaAsset::KIND_VIDEO ? '.mp4' : '.webp'),
            'sha256' => hash('sha256', $id),
            'mime_type' => $mimeType,
            'byte_size' => 1024,
            'width' => $kind === WallboardMediaAsset::KIND_IMAGE ? 1920 : null,
            'height' => $kind === WallboardMediaAsset::KIND_IMAGE ? 1080 : null,
            'duration_seconds' => $duration,
            'status' => WallboardMediaAsset::STATUS_READY,
            'version' => $version,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'Wallboard Playlist Preview User',
            'first_name' => 'Wallboard',
            'last_name' => 'Playlist Preview User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-playlist-preview-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Wallboard playlist preview role',
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
        $token = $user->createToken('Wallboard playlist preview test', ['*', 'client:admin'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
