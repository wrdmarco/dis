<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPairingRequest;
use App\Models\WallboardPlaylist;
use App\Models\WallboardSession;
use App\Services\WallboardService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WallboardManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallboard_management_requires_authentication_and_the_dedicated_permission(): void
    {
        $this->getJson('/api/admin/wallboards')->assertUnauthorized();

        $unprivileged = $this->user('wallboard-unprivileged@example.test', []);
        $this->asAdminClient($unprivileged)
            ->getJson('/api/admin/wallboards')
            ->assertForbidden();

        $manager = $this->user('wallboard-manager@example.test', ['wallboards.manage']);
        $pendingToken = $manager->createToken(
            'Wallboard pending 2FA test',
            ['2fa:pending', 'client:admin'],
            now()->addMinutes(5),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($pendingToken)
            ->getJson('/api/admin/wallboards')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $this->asAdminClient($manager)
            ->getJson('/api/admin/wallboards')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_manager_can_create_update_revoke_and_delete_a_strictly_configured_wallboard(): void
    {
        $manager = $this->user('wallboard-lifecycle@example.test', ['wallboards.manage']);
        $client = $this->asAdminClient($manager);

        $create = $client->postJson('/api/admin/wallboards', [
            'name' => 'Crisisruimte noord',
            'layout' => 'fullscreen_map',
            'configuration' => [
                'theme' => 'dark',
                'refresh_seconds' => 15,
                'map' => [
                    'show_test_incidents' => true,
                    'show_historical_incidents' => true,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Crisisruimte noord')
            ->assertJsonPath('data.layout', 'fullscreen_map')
            ->assertJsonPath('data.configuration.refresh_seconds', 15)
            ->assertJsonPath('data.configuration.map.show_routes', true)
            ->assertJsonPath('data.configuration.map.show_test_incidents', false)
            ->assertJsonPath('data.config_version', 1);
        $wallboard = Wallboard::query()->findOrFail($create->json('data.id'));

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 1,
            'configuration' => [
                'theme' => 'light',
                'map' => [
                    'show_routes' => false,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.configuration.theme', 'light')
            ->assertJsonPath('data.configuration.map.show_routes', false)
            ->assertJsonPath('data.configuration.refresh_seconds', 15)
            ->assertJsonPath('data.config_version', 2);

        WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash('sha256', 'management-session'),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        WallboardPairingRequest::query()->create([
            'code_hash' => hash('sha256', 'management-code'),
            'secret_hash' => hash('sha256', 'management-secret'),
            'wallboard_id' => $wallboard->id,
            'approved_by' => $manager->id,
            'approved_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);
        $client->postJson('/api/admin/wallboards/'.$wallboard->id.'/sessions/revoke')
            ->assertOk()
            ->assertJsonPath('data.revoked', true);
        $this->assertNotNull(WallboardSession::query()->firstOrFail()->revoked_at);
        $this->assertDatabaseCount('wallboard_pairing_requests', 0);

        foreach (['wallboards.created', 'wallboards.updated', 'wallboards.sessions_revoked'] as $action) {
            $this->assertTrue(AuditLog::query()->where('action', $action)->where('target_id', $wallboard->id)->exists(), $action);
        }

        $client->deleteJson('/api/admin/wallboards/'.$wallboard->id)->assertNoContent();
        $this->assertDatabaseMissing('wallboards', ['id' => $wallboard->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'wallboards.deleted')->where('target_id', $wallboard->id)->exists());
    }

    public function test_active_incident_playlist_assignment_is_validated_versioned_and_audited_per_screen(): void
    {
        $manager = $this->user('wallboard-active-playlist-management@example.test', ['wallboards.manage']);
        $configuration = WallboardConfiguration::defaults();
        $basePlaylist = WallboardPlaylist::query()->create([
            'name' => 'Normale playlist',
            'configuration' => $configuration,
            'version' => 1,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
        $firstActivePlaylist = WallboardPlaylist::query()->create([
            'name' => 'Eerste actieve inzet',
            'configuration' => $configuration,
            'version' => 1,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
        $secondActivePlaylist = WallboardPlaylist::query()->create([
            'name' => 'Tweede actieve inzet',
            'configuration' => $configuration,
            'version' => 1,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
        $client = $this->asAdminClient($manager);

        $created = $client->postJson('/api/admin/wallboards', [
            'name' => 'Scherm met inzetregie',
            'playlist_id' => $basePlaylist->id,
            'active_incident_playlist_id' => $firstActivePlaylist->id,
        ])->assertCreated()
            ->assertJsonPath('data.active_incident_playlist_id', $firstActivePlaylist->id)
            ->assertJsonPath('data.active_incident_playlist.id', $firstActivePlaylist->id)
            ->assertJsonPath('data.config_version', 1)
            ->assertJsonPath('data.control_version', 1);
        $wallboard = Wallboard::query()->findOrFail($created->json('data.id'));
        $createdAudit = AuditLog::query()
            ->where('action', 'wallboards.created')
            ->where('target_id', $wallboard->id)
            ->firstOrFail();
        $this->assertSame($firstActivePlaylist->id, $createdAudit->metadata['active_incident_playlist_id']);

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'active_incident_playlist_id' => $secondActivePlaylist->id,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['expected_config_version']]]);
        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 1,
            'active_incident_playlist_id' => (string) str()->ulid(),
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['active_incident_playlist_id']]]);

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 1,
            'active_incident_playlist_id' => $secondActivePlaylist->id,
        ])->assertOk()
            ->assertJsonPath('data.active_incident_playlist_id', $secondActivePlaylist->id)
            ->assertJsonPath('data.active_incident_playlist.id', $secondActivePlaylist->id)
            ->assertJsonPath('data.config_version', 2)
            ->assertJsonPath('data.control_version', 2);
        $updatedAudit = AuditLog::query()
            ->where('action', 'wallboards.updated')
            ->where('target_id', $wallboard->id)
            ->latest('created_at')
            ->firstOrFail();
        $this->assertContains('active_incident_playlist_id', $updatedAudit->metadata['changed_fields']);
        $this->assertSame($firstActivePlaylist->id, $updatedAudit->metadata['previous_active_incident_playlist_id']);
        $this->assertSame($secondActivePlaylist->id, $updatedAudit->metadata['active_incident_playlist_id']);

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 2,
            'active_incident_playlist_id' => $secondActivePlaylist->id,
        ])->assertOk()
            ->assertJsonPath('data.config_version', 2)
            ->assertJsonPath('data.control_version', 2);
        $unchangedAudit = AuditLog::query()
            ->where('action', 'wallboards.updated')
            ->where('target_id', $wallboard->id)
            ->whereKeyNot($updatedAudit->id)
            ->firstOrFail();
        $this->assertSame(2, AuditLog::query()
            ->where('action', 'wallboards.updated')
            ->where('target_id', $wallboard->id)
            ->count());
        $this->assertNotContains('active_incident_playlist_id', $unchangedAudit->metadata['changed_fields']);

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 2,
            'active_incident_playlist_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.active_incident_playlist_id', null)
            ->assertJsonPath('data.active_incident_playlist', null)
            ->assertJsonPath('data.config_version', 3)
            ->assertJsonPath('data.control_version', 3);
        $this->assertDatabaseHas('wallboards', [
            'id' => $wallboard->id,
            'active_incident_playlist_id' => null,
        ]);
    }

    public function test_unknown_or_internally_inconsistent_wallboard_configuration_is_rejected(): void
    {
        $manager = $this->user('wallboard-validation@example.test', ['wallboards.manage']);
        $client = $this->asAdminClient($manager);

        $client->postJson('/api/admin/wallboards', [
            'name' => 'Onveilig wallboard',
            'layout' => 'external_url',
            'configuration' => ['external_url' => 'https://example.test'],
        ])->assertUnprocessable();

        $client->postJson('/api/admin/wallboards', [
            'name' => 'Inconsistente kaart',
            'configuration' => [
                'map' => [
                    'show_live_locations' => false,
                    'show_routes' => true,
                ],
            ],
        ])->assertUnprocessable();

        $client->postJson('/api/admin/wallboards', [
            'name' => 'Onvolledige alarmfocus',
            'configuration' => [
                'focus' => [
                    'real_alarm' => [
                        'duration_seconds' => 30,
                        'show_response_feed' => true,
                    ],
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['configuration.focus.real_alarm.enabled']]]);

        $this->assertDatabaseCount('wallboards', 0);
    }

    public function test_display_profile_is_per_wallboard_defaulted_validated_versioned_and_audited(): void
    {
        $manager = $this->user('wallboard-display-profile@example.test', ['wallboards.manage']);
        $client = $this->asAdminClient($manager);

        $automatic = $client->postJson('/api/admin/wallboards', [
            'name' => 'Automatisch scherm',
        ])->assertCreated()
            ->assertJsonPath('data.display_profile', Wallboard::DISPLAY_PROFILE_AUTO)
            ->assertJsonPath('data.config_version', 1)
            ->assertJsonPath('data.control_version', 1);
        $this->assertDatabaseHas('wallboards', [
            'id' => $automatic->json('data.id'),
            'display_profile' => Wallboard::DISPLAY_PROFILE_AUTO,
        ]);

        $fourK = $client->postJson('/api/admin/wallboards', [
            'name' => 'Vier K scherm',
            'display_profile' => Wallboard::DISPLAY_PROFILE_4K,
        ])->assertCreated()
            ->assertJsonPath('data.display_profile', Wallboard::DISPLAY_PROFILE_4K);
        $wallboard = Wallboard::query()->findOrFail($fourK->json('data.id'));
        $sibling = $client->postJson('/api/admin/wallboards', [
            'name' => 'Gedeelde playlist, eigen profiel',
            'playlist_id' => $fourK->json('data.playlist_id'),
        ])->assertCreated()
            ->assertJsonPath('data.display_profile', Wallboard::DISPLAY_PROFILE_AUTO);

        $manualPageId = (string) $wallboard->configuration['pages'][0]['id'];
        $rotationStartedAt = now()->subMinutes(3);
        $wallboard->forceFill([
            'manual_page_id' => $manualPageId,
            'manual_page_set_at' => now()->subMinute(),
            'rotation_started_at' => $rotationStartedAt,
        ])->save();
        $storedRotationStartedAt = DB::table('wallboards')
            ->where('id', $wallboard->id)
            ->value('rotation_started_at');
        WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash('sha256', 'display-profile-session'),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        WallboardPairingRequest::query()->create([
            'code_hash' => hash('sha256', 'display-profile-code'),
            'secret_hash' => hash('sha256', 'display-profile-secret'),
            'wallboard_id' => $wallboard->id,
            'expires_at' => now()->addMinutes(5),
        ]);

        $client->postJson('/api/admin/wallboards', [
            'name' => 'Onbekend profiel',
            'display_profile' => '1440p',
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['display_profile']]]);

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'display_profile' => Wallboard::DISPLAY_PROFILE_1080P,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['expected_config_version']]]);

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 1,
            'display_profile' => Wallboard::DISPLAY_PROFILE_1080P,
        ])->assertOk()
            ->assertJsonPath('data.display_profile', Wallboard::DISPLAY_PROFILE_1080P)
            ->assertJsonPath('data.config_version', 2)
            ->assertJsonPath('data.control_version', 2);

        $wallboard->refresh();
        $this->assertSame(Wallboard::DISPLAY_PROFILE_1080P, $wallboard->display_profile);
        $this->assertSame($manualPageId, $wallboard->manual_page_id);
        $this->assertSame(
            $storedRotationStartedAt,
            DB::table('wallboards')->where('id', $wallboard->id)->value('rotation_started_at'),
        );
        $this->assertDatabaseHas('wallboard_sessions', [
            'wallboard_id' => $wallboard->id,
            'revoked_at' => null,
        ]);
        $this->assertDatabaseHas('wallboard_pairing_requests', [
            'wallboard_id' => $wallboard->id,
            'consumed_at' => null,
        ]);
        $this->assertSame(
            Wallboard::DISPLAY_PROFILE_AUTO,
            Wallboard::query()->findOrFail($sibling->json('data.id'))->display_profile,
        );
        $audit = AuditLog::query()
            ->where('action', 'wallboards.updated')
            ->where('target_id', $wallboard->id)
            ->latest('created_at')
            ->firstOrFail();
        $this->assertContains('display_profile', $audit->metadata['changed_fields']);

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 1,
            'display_profile' => Wallboard::DISPLAY_PROFILE_4K,
        ])->assertConflict();
        $this->assertSame(Wallboard::DISPLAY_PROFILE_1080P, $wallboard->fresh()->display_profile);

        $client->patchJson('/api/admin/wallboards/'.$wallboard->id, [
            'expected_config_version' => 2,
            'display_profile' => Wallboard::DISPLAY_PROFILE_1080P,
        ])->assertOk()
            ->assertJsonPath('data.config_version', 2)
            ->assertJsonPath('data.control_version', 2);
    }

    public function test_display_profile_migration_backfills_existing_wallboards_to_auto(): void
    {
        $manager = $this->user('wallboard-profile-migration@example.test', ['wallboards.manage']);
        $migration = require database_path('migrations/2026_07_19_000005_add_display_profile_to_wallboards.php');
        $migration->down();

        $wallboardId = (string) str()->ulid();
        DB::table('wallboards')->insert([
            'id' => $wallboardId,
            'name' => 'Bestaand scherm',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => json_encode(WallboardConfiguration::defaults(), JSON_THROW_ON_ERROR),
            'is_enabled' => true,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame(
            Wallboard::DISPLAY_PROFILE_AUTO,
            DB::table('wallboards')->where('id', $wallboardId)->value('display_profile'),
        );
    }

    public function test_management_online_state_is_derived_server_side_from_a_current_active_session(): void
    {
        config()->set('app.timezone', 'Europe/Amsterdam');
        $this->travelTo(CarbonImmutable::parse('2026-07-19 10:01:00', 'Europe/Amsterdam'));
        $manager = $this->user('wallboard-online@example.test', ['wallboards.manage']);
        $wallboard = Wallboard::query()->create([
            'name' => 'Online wallboard',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::defaults(),
            'is_enabled' => true,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash('sha256', 'online-session'),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => null,
        ]);
        DB::table('wallboard_sessions')->where('id', $session->id)->update([
            'last_seen_at' => '2026-07-19 10:00:30.000000+00',
        ]);

        $client = $this->asAdminClient($manager);
        $client->getJson('/api/admin/wallboards')
            ->assertOk()
            ->assertJsonPath('data.0.active_sessions_count', 1)
            ->assertJsonPath('data.0.is_online', true);

        DB::table('wallboard_sessions')->where('id', $session->id)->update([
            'last_seen_at' => '2026-07-19 07:58:59.000000+00',
        ]);
        $client->getJson('/api/admin/wallboards')
            ->assertOk()
            ->assertJsonPath('data.0.active_sessions_count', 1)
            ->assertJsonPath('data.0.is_online', false);

        $session->forceFill(['revoked_at' => now()])->save();
        $client->getJson('/api/admin/wallboards')
            ->assertOk()
            ->assertJsonPath('data.0.active_sessions_count', 0)
            ->assertJsonPath('data.0.is_online', false);
    }

    public function test_management_counts_permanent_sessions_and_rejects_expired_legacy_sessions(): void
    {
        config()->set('app.timezone', 'Europe/Amsterdam');
        $service = app(WallboardService::class);
        $activeSessions = new \ReflectionMethod($service, 'activeSessions');

        foreach (['2026-07-19', '2026-01-19'] as $date) {
            $this->travelTo(CarbonImmutable::parse($date.' 10:01:00', 'Europe/Amsterdam'));
            $wallboard = new Wallboard;
            $expired = (new WallboardSession)->newFromBuilder([
                'expires_at' => $date.' 10:00:59.000000+00',
            ]);
            $current = (new WallboardSession)->newFromBuilder([
                'expires_at' => $date.' 10:01:01.000000+00',
            ]);
            $permanent = (new WallboardSession)->newFromBuilder([
                'expires_at' => null,
            ]);

            $wallboard->setRelation('nonRevokedSessions', collect([$expired, $current, $permanent]));
            $resolved = $activeSessions->invoke($service, $wallboard);

            $this->assertCount(2, $resolved, $date);
            $this->assertSame($current, $resolved->first(), $date);
            $this->assertSame($permanent, $resolved->last(), $date);
        }
    }

    public function test_disabling_a_wallboard_immediately_revokes_sessions_and_pending_pairing(): void
    {
        $manager = $this->user('wallboard-disable@example.test', ['wallboards.manage']);
        $wallboard = Wallboard::query()->create([
            'name' => 'Uitschakelbaar',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::defaults(),
            'is_enabled' => true,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]);
        WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash('sha256', 'disable-session'),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        WallboardPairingRequest::query()->create([
            'code_hash' => hash('sha256', 'disable-code'),
            'secret_hash' => hash('sha256', 'disable-secret'),
            'wallboard_id' => $wallboard->id,
            'approved_by' => $manager->id,
            'approved_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->asAdminClient($manager)
            ->patchJson('/api/admin/wallboards/'.$wallboard->id, ['is_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);

        $this->assertNotNull(WallboardSession::query()->firstOrFail()->revoked_at);
        $this->assertDatabaseCount('wallboard_pairing_requests', 0);
    }

    public function test_default_role_seed_grants_wallboard_management_only_to_system_administrators(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $permission = Permission::query()->where('name', 'wallboards.manage')->firstOrFail();

        $this->assertTrue(Role::query()->where('name', 'system-administrator')->firstOrFail()->permissions()->whereKey($permission->id)->exists());
        foreach (['national-coordinator', 'incident-coordinator', 'operator-pilot', 'support-staff', 'auditor'] as $roleName) {
            $this->assertFalse(Role::query()->where('name', $roleName)->firstOrFail()->permissions()->whereKey($permission->id)->exists(), $roleName);
        }
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::query()->create([
            'name' => 'Wallboard Test User',
            'first_name' => 'Wallboard',
            'last_name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Wallboard test role',
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
        $token = $user->createToken('Wallboard admin test', ['*', 'client:admin'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
