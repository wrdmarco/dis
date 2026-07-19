<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Services\WallboardPlaylistResolver;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WallboardPlaylistTest extends TestCase
{
    use RefreshDatabase;

    public function test_playlist_endpoints_require_authentication_and_wallboard_management_permission(): void
    {
        $this->getJson('/api/admin/wallboard-playlists')->assertUnauthorized();

        $unprivileged = $this->user('playlist-unprivileged@example.test', []);
        $this->asAdminClient($unprivileged)
            ->getJson('/api/admin/wallboard-playlists')
            ->assertForbidden();

        $manager = $this->user('playlist-manager@example.test', ['wallboards.manage']);
        $this->asAdminClient($manager)
            ->getJson('/api/admin/wallboard-playlists')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_legacy_rss_playlist_payload_exposes_the_backward_compatible_default_item_limit(): void
    {
        $manager = $this->user('playlist-legacy-rss@example.test', ['wallboards.manage']);
        $legacyConfiguration = WallboardConfiguration::defaults();
        $legacyConfiguration['ticker'] = [
            'enabled' => true,
            'sources' => [[
                'id' => 'weather',
                'type' => 'rss',
                'label' => 'Buienradar.nl',
                'url' => 'https://data.buienradar.nl/1.0/feed/xml/rssbuienradar',
            ]],
        ];
        $playlist = WallboardPlaylist::query()->create([
            'name' => 'Legacy RSS',
            'configuration' => $legacyConfiguration,
            'version' => 1,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);

        $this->asAdminClient($manager)
            ->getJson('/api/admin/wallboard-playlists')
            ->assertOk()
            ->assertJsonPath('data.0.id', $playlist->id)
            ->assertJsonPath(
                'data.0.configuration.ticker.sources.0.max_items',
                WallboardConfiguration::DEFAULT_TICKER_RSS_MAX_ITEMS,
            );

        $this->assertArrayNotHasKey('max_items', $playlist->configuration['ticker']['sources'][0]);
    }

    public function test_new_wallboard_gets_its_own_playlist_unless_an_existing_playlist_is_selected(): void
    {
        $manager = $this->user('playlist-create@example.test', ['wallboards.manage']);
        $client = $this->asAdminClient($manager);

        $ownedResponse = $client->postJson('/api/admin/wallboards', [
            'name' => 'Eigen scherm',
            'configuration' => [
                'ticker' => [
                    'enabled' => true,
                    'sources' => [[
                        'id' => 'intern',
                        'type' => 'internal',
                        'label' => 'Melding',
                        'text' => 'Eigen playlisttekst',
                    ]],
                ],
            ],
        ])->assertCreated();

        $owned = Wallboard::query()->with('playlist')->findOrFail($ownedResponse->json('data.id'));
        $this->assertNotNull($owned->playlist_id);
        $this->assertSame('Eigen scherm', $owned->playlist?->name);
        $this->assertSame($owned->configuration, $owned->playlist?->configuration);
        $this->assertSame('Eigen playlisttekst', $owned->playlist?->configuration['ticker']['sources'][0]['text']);

        $sharedConfiguration = $this->configuration(['map'], 'Gedeelde tickertekst');
        $shared = WallboardPlaylist::query()->create([
            'name' => 'Gedeeld draaiboek',
            'configuration' => $sharedConfiguration,
            'version' => 1,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
        $sharedResponse = $client->postJson('/api/admin/wallboards', [
            'name' => 'Gedeeld scherm',
            'playlist_id' => $shared->id,
        ])->assertCreated()
            ->assertJsonPath('data.playlist_id', $shared->id)
            ->assertJsonPath('data.playlist.name', 'Gedeeld draaiboek');

        $linked = Wallboard::query()->findOrFail($sharedResponse->json('data.id'));
        $this->assertSame($sharedConfiguration, $linked->configuration);
        $this->assertDatabaseCount('wallboard_playlists', 2);
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboards.playlist_assigned')
            ->where('target_id', $linked->id)
            ->exists());

        $client->postJson('/api/admin/wallboards', [
            'name' => 'Dubbele bron',
            'playlist_id' => $shared->id,
            'configuration' => ['theme' => 'light'],
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['configuration']]]);
    }

    public function test_playlist_update_propagates_to_every_linked_wallboard_and_preserves_per_screen_control(): void
    {
        $this->travelTo('2026-07-19 12:00:00');
        $manager = $this->user('playlist-propagate@example.test', ['wallboards.manage']);
        $oldConfiguration = $this->configuration(['map', 'briefing'], 'Oud bericht');
        $playlist = $this->playlist($manager, 'Operationeel', $oldConfiguration);
        $first = $this->wallboard($manager, $playlist, 'Scherm noord', $oldConfiguration, 'briefing');
        $second = $this->wallboard($manager, $playlist, 'Scherm zuid', $oldConfiguration, 'map');

        $newConfiguration = $this->configuration(['map'], 'Nieuw bericht');
        $this->asAdminClient($manager)
            ->patchJson('/api/admin/wallboard-playlists/'.$playlist->id, [
                'expected_version' => 1,
                'configuration' => $newConfiguration,
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.linked_wallboards_count', 2)
            ->assertJsonPath('data.configuration.ticker.sources.0.text', 'Nieuw bericht');

        $playlist->refresh();
        $first->refresh();
        $second->refresh();
        $this->assertSame($newConfiguration, $playlist->configuration);
        $this->assertSame($newConfiguration, $first->configuration);
        $this->assertSame($newConfiguration, $second->configuration);
        $this->assertSame(2, $first->config_version);
        $this->assertSame(2, $first->control_version);
        $this->assertSame(2, $second->config_version);
        $this->assertSame(2, $second->control_version);
        $this->assertNull($first->manual_page_id);
        $this->assertNull($first->manual_page_set_at);
        $this->assertSame('map', $second->manual_page_id);
        $this->assertNotNull($second->manual_page_set_at);
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboard_playlists.updated')
            ->where('target_id', $playlist->id)
            ->exists());
    }

    public function test_assignment_is_atomic_versioned_audited_and_copies_the_playlist_snapshot(): void
    {
        $manager = $this->user('playlist-assign@example.test', ['wallboards.manage']);
        $oldConfiguration = $this->configuration(['map', 'briefing'], 'Oude playlist');
        $newConfiguration = $this->configuration(['summary'], 'Nieuwe playlist');
        $oldPlaylist = $this->playlist($manager, 'Oud', $oldConfiguration);
        $newPlaylist = $this->playlist($manager, 'Nieuw', $newConfiguration);
        $wallboard = $this->wallboard($manager, $oldPlaylist, 'Wisselscherm', $oldConfiguration, 'briefing');
        $wallboard->forceFill(['display_profile' => Wallboard::DISPLAY_PROFILE_4K])->save();

        $this->asAdminClient($manager)
            ->patchJson('/api/admin/wallboards/'.$wallboard->id.'/playlist', [
                'playlist_id' => $newPlaylist->id,
                'expected_config_version' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('data.playlist_id', $newPlaylist->id)
            ->assertJsonPath('data.display_profile', Wallboard::DISPLAY_PROFILE_4K)
            ->assertJsonPath('data.config_version', 2)
            ->assertJsonPath('data.control_version', 2)
            ->assertJsonPath('data.configuration.pages.0.id', 'summary');

        $wallboard->refresh();
        $this->assertSame($newPlaylist->id, $wallboard->playlist_id);
        $this->assertSame(Wallboard::DISPLAY_PROFILE_4K, $wallboard->display_profile);
        $this->assertSame($newConfiguration, $wallboard->configuration);
        $this->assertNull($wallboard->manual_page_id);
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboards.playlist_assigned')
            ->where('target_id', $wallboard->id)
            ->exists());

        $this->asAdminClient($manager)
            ->patchJson('/api/admin/wallboards/'.$wallboard->id.'/playlist', [
                'playlist_id' => $oldPlaylist->id,
                'expected_config_version' => 1,
            ])
            ->assertConflict();
        $this->assertSame($newPlaylist->id, $wallboard->fresh()->playlist_id);
    }

    public function test_legacy_wallboard_configuration_patch_updates_the_shared_playlist_and_all_screens(): void
    {
        $manager = $this->user('playlist-legacy@example.test', ['wallboards.manage']);
        $oldConfiguration = $this->configuration(['map'], 'Voor wijziging');
        $playlist = $this->playlist($manager, 'Legacy contract', $oldConfiguration);
        $first = $this->wallboard($manager, $playlist, 'Legacy een', $oldConfiguration);
        $second = $this->wallboard($manager, $playlist, 'Legacy twee', $oldConfiguration);

        $this->asAdminClient($manager)
            ->patchJson('/api/admin/wallboards/'.$first->id, [
                'expected_config_version' => 1,
                'configuration' => [
                    'theme' => 'light',
                    'ticker' => [
                        'enabled' => true,
                        'sources' => [[
                            'id' => 'intern',
                            'type' => 'internal',
                            'label' => 'Intern',
                            'text' => 'Na wijziging',
                        ]],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.configuration.theme', 'light')
            ->assertJsonPath('data.playlist.version', 2);

        $playlist->refresh();
        $first->refresh();
        $second->refresh();
        $this->assertSame(2, $playlist->version);
        $this->assertSame('light', $playlist->configuration['theme']);
        $this->assertSame($playlist->configuration, $first->configuration);
        $this->assertSame($playlist->configuration, $second->configuration);
        $this->assertSame(2, $first->config_version);
        $this->assertSame(2, $second->config_version);
    }

    public function test_stale_updates_and_deletes_conflict_and_linked_playlist_cannot_be_deleted(): void
    {
        $manager = $this->user('playlist-delete@example.test', ['wallboards.manage']);
        $configuration = $this->configuration(['map'], 'Versiebeveiligd');
        $linkedPlaylist = $this->playlist($manager, 'Gekoppeld', $configuration);
        $this->wallboard($manager, $linkedPlaylist, 'Gekoppeld scherm', $configuration);
        $client = $this->asAdminClient($manager);

        $client->patchJson('/api/admin/wallboard-playlists/'.$linkedPlaylist->id, [
            'expected_version' => 1,
            'name' => 'Gekoppeld hernoemd',
        ])->assertOk()->assertJsonPath('data.version', 2);
        $linkedUpdateAudit = AuditLog::query()
            ->where('action', 'wallboard_playlists.updated')
            ->where('target_id', $linkedPlaylist->id)
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame(1, $linkedUpdateAudit->metadata['linked_wallboards_count']);
        $client->patchJson('/api/admin/wallboard-playlists/'.$linkedPlaylist->id, [
            'expected_version' => 1,
            'name' => 'Stale wijziging',
        ])->assertConflict();
        $client->deleteJson('/api/admin/wallboard-playlists/'.$linkedPlaylist->id.'?expected_version=2')
            ->assertConflict();
        $this->assertDatabaseHas('wallboard_playlists', ['id' => $linkedPlaylist->id]);

        $orphan = $this->playlist($manager, 'Los', $configuration);
        $client->patchJson('/api/admin/wallboard-playlists/'.$orphan->id, [
            'expected_version' => 1,
            'name' => 'Los hernoemd',
        ])->assertOk()->assertJsonPath('data.version', 2);
        $client->deleteJson('/api/admin/wallboard-playlists/'.$orphan->id.'?expected_version=1')
            ->assertConflict();
        $client->deleteJson('/api/admin/wallboard-playlists/'.$orphan->id.'?expected_version=2')
            ->assertNoContent();
        $this->assertDatabaseMissing('wallboard_playlists', ['id' => $orphan->id]);
        $this->assertTrue(AuditLog::query()
            ->where('action', 'wallboard_playlists.deleted')
            ->where('target_id', $orphan->id)
            ->exists());
    }

    public function test_resolver_uses_playlist_as_source_of_truth_when_fallback_snapshot_drifted(): void
    {
        $manager = $this->user('playlist-resolver@example.test', ['wallboards.manage']);
        $configuration = $this->configuration(['map'], 'Bron van waarheid');
        $playlist = $this->playlist($manager, 'Bron', $configuration);
        $wallboard = $this->wallboard($manager, $playlist, 'Resolver', $configuration);
        $drifted = $configuration;
        $drifted['theme'] = 'light';
        DB::table('wallboards')->where('id', $wallboard->id)->update([
            'configuration' => json_encode($drifted, JSON_THROW_ON_ERROR),
        ]);

        $resolved = app(WallboardPlaylistResolver::class)->resolve($wallboard->fresh());

        $this->assertSame($configuration, $resolved);
        $this->asAdminClient($manager)
            ->getJson('/api/admin/wallboards/'.$wallboard->id)
            ->assertOk()
            ->assertJsonPath('data.configuration.theme', 'dark');
    }

    public function test_migration_backfills_one_playlist_per_existing_wallboard_without_configuration_loss(): void
    {
        $manager = $this->user('playlist-migration@example.test', ['wallboards.manage']);
        $migration = require database_path('migrations/2026_07_19_000004_create_wallboard_playlists.php');
        $migration->down();

        $firstConfiguration = $this->configuration(['map'], 'Exact een');
        $secondConfiguration = $this->configuration(['summary'], 'Exact twee');
        foreach ([
            ['name' => 'Bestaand een', 'configuration' => $firstConfiguration],
            ['name' => 'Bestaand twee', 'configuration' => $secondConfiguration],
        ] as $legacy) {
            DB::table('wallboards')->insert([
                'id' => (string) Str::ulid(),
                'name' => $legacy['name'],
                'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
                'configuration' => json_encode($legacy['configuration'], JSON_THROW_ON_ERROR),
                'config_version' => 7,
                'control_version' => 9,
                'rotation_started_at' => now(),
                'is_enabled' => true,
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration->up();

        $wallboards = DB::table('wallboards')->orderBy('name')->get();
        $this->assertCount(2, $wallboards);
        $this->assertDatabaseCount('wallboard_playlists', 2);
        $this->assertNotSame($wallboards[0]->playlist_id, $wallboards[1]->playlist_id);
        foreach ($wallboards as $wallboard) {
            $playlist = DB::table('wallboard_playlists')->where('id', $wallboard->playlist_id)->first();
            $this->assertNotNull($playlist);
            $this->assertSame($wallboard->name, $playlist->name);
            $this->assertSame(
                json_decode((string) $wallboard->configuration, true, 512, JSON_THROW_ON_ERROR),
                json_decode((string) $playlist->configuration, true, 512, JSON_THROW_ON_ERROR),
            );
            $this->assertSame(1, (int) $playlist->version);
        }
    }

    /**
     * @param  list<string>  $pageIds
     * @return array<string, mixed>
     */
    private function configuration(array $pageIds, string $tickerText): array
    {
        $pages = [];
        foreach ($pageIds as $pageId) {
            $type = $pageId === 'briefing' ? 'message' : $pageId;
            $pages[] = [
                'id' => $pageId,
                'name' => ucfirst($pageId),
                'type' => $type,
                'duration_seconds' => 30,
                'options' => $type === 'message' ? ['body' => 'Operationele briefing'] : [],
            ];
        }

        return WallboardConfiguration::normalize([
            'pages' => $pages,
            'incident_override' => [
                'enabled' => false,
                'page_id' => $pageIds[0],
            ],
            'ticker' => [
                'enabled' => true,
                'sources' => [[
                    'id' => 'intern',
                    'type' => 'internal',
                    'label' => 'Intern',
                    'text' => $tickerText,
                ]],
            ],
        ]);
    }

    /** @param array<string, mixed> $configuration */
    private function playlist(
        User $actor,
        string $name,
        array $configuration,
    ): WallboardPlaylist {
        return WallboardPlaylist::query()->create([
            'name' => $name,
            'configuration' => $configuration,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @param array<string, mixed> $configuration */
    private function wallboard(
        User $actor,
        WallboardPlaylist $playlist,
        string $name,
        array $configuration,
        ?string $manualPageId = null,
    ): Wallboard {
        return Wallboard::query()->create([
            'name' => $name,
            'playlist_id' => $playlist->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => $configuration,
            'config_version' => 1,
            'control_version' => 1,
            'manual_page_id' => $manualPageId,
            'manual_page_set_at' => $manualPageId === null ? null : now(),
            'rotation_started_at' => now()->subMinute(),
            'is_enabled' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'Wallboard Playlist Test User',
            'first_name' => 'Wallboard',
            'last_name' => 'Playlist Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-playlist-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Wallboard playlist test role',
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
        $token = $user->createToken('Wallboard playlist admin test', ['*', 'client:admin'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
