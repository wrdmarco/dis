<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPairingRequest;
use App\Models\WallboardSession;
use App\Support\WallboardConfiguration;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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

        $this->assertDatabaseCount('wallboards', 0);
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
