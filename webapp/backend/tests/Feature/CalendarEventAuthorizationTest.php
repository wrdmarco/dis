<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CalendarEventAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_seed_defaults_match_the_least_privilege_upgrade_policy(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $allPermissions = [
            'calendar.manage',
            'calendar.view',
            'operational-weather.view',
            'uav-forecast.view',
        ];

        $this->assertSame(
            $allPermissions,
            $this->rolePermissionNames(
                Role::query()->where('name', Role::SYSTEM_ADMINISTRATOR)->sole(),
                $allPermissions,
            ),
        );

        foreach (['national-coordinator', 'deployment-coordinator', 'operator-pilot'] as $roleName) {
            $this->assertSame(
                ['calendar.view'],
                $this->rolePermissionNames(
                    Role::query()->where('name', $roleName)->sole(),
                    $allPermissions,
                ),
                $roleName,
            );
        }

        foreach (['support-staff', 'auditor', 'request-handler'] as $roleName) {
            $this->assertSame(
                [],
                $this->rolePermissionNames(
                    Role::query()->where('name', $roleName)->sole(),
                    $allPermissions,
                ),
                $roleName,
            );
        }
    }

    public function test_permission_migration_backfills_only_compatible_roles_and_operator_calendar_access(): void
    {
        $unrelatedRole = Role::query()->create([
            'name' => 'existing-unrelated-web-role',
            'display_name' => 'Existing unrelated web role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $settingsManagerRole = Role::query()->create([
            'name' => 'existing-settings-manager',
            'display_name' => 'Existing settings manager',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $weatherManagerRole = Role::query()->create([
            'name' => 'existing-weather-manager',
            'display_name' => 'Existing weather manager',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $operatorRole = Role::query()->create([
            'name' => 'existing-calendar-operator',
            'display_name' => 'Existing calendar operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $settingsManagerRole->permissions()->attach(
            Permission::query()->where('name', 'settings.manage')->sole()->id,
            ['created_at' => now()],
        );
        $weatherManagerRole->permissions()->attach(
            Permission::query()->where('name', 'knmi.manage')->sole()->id,
            ['created_at' => now()],
        );

        $migration = require database_path('migrations/2026_07_27_000011_add_calendar_and_forecast_permissions.php');
        $migration->up();
        $migration->up();

        $allPermissions = [
            'calendar.manage',
            'calendar.view',
            'operational-weather.view',
            'uav-forecast.view',
        ];

        $this->assertSame([], $this->rolePermissionNames($unrelatedRole, $allPermissions));
        $this->assertSame(
            ['calendar.manage', 'calendar.view'],
            $this->rolePermissionNames($settingsManagerRole, $allPermissions),
        );
        $this->assertSame(
            ['operational-weather.view', 'uav-forecast.view'],
            $this->rolePermissionNames($weatherManagerRole, $allPermissions),
        );
        $this->assertSame(['calendar.view'], $this->rolePermissionNames($operatorRole, $allPermissions));
        $this->assertSame(
            [],
            $this->rolePermissionNames(
                Role::query()->where('name', 'request-handler')->sole(),
                $allPermissions,
            ),
        );
    }

    public function test_calendar_read_requires_an_explicit_view_permission(): void
    {
        $event = CalendarEvent::query()->create([
            'title' => 'Landelijke oefening',
            'type' => 'exercise',
            'starts_at' => now()->addDay(),
        ]);

        $this->getJson('/api/calendar-events')->assertUnauthorized();

        $unprivileged = $this->user('calendar-unprivileged@example.test');
        $this->grant($unprivileged, ['settings.manage']);
        $this->asWebClient($unprivileged)
            ->getJson('/api/calendar-events')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $viewer = $this->user('calendar-viewer@example.test');
        $this->grant($viewer, ['calendar.view']);
        $this->asWebClient($viewer)
            ->getJson('/api/calendar-events')
            ->assertOk()
            ->assertJsonPath('data.0.id', $event->id)
            ->assertJsonPath('data.0.title', 'Landelijke oefening');
    }

    public function test_calendar_management_is_separate_from_view_and_system_settings(): void
    {
        $viewer = $this->user('calendar-read-only@example.test');
        $this->grant($viewer, ['calendar.view']);
        $payload = [
            'title' => 'Teamavond',
            'type' => 'meeting',
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'location_label' => 'Utrecht',
        ];

        $this->asWebClient($viewer)
            ->postJson('/api/calendar-events', $payload)
            ->assertForbidden();

        $settingsManager = $this->user('calendar-settings-only@example.test');
        $this->grant($settingsManager, ['settings.manage']);
        $this->asWebClient($settingsManager)
            ->postJson('/api/calendar-events', $payload)
            ->assertForbidden();

        $manageOnly = $this->user('calendar-manage-only@example.test');
        $this->grant($manageOnly, ['calendar.manage']);
        $this->asWebClient($manageOnly)
            ->getJson('/api/calendar-events/team-options')
            ->assertForbidden();
        $this->asWebClient($manageOnly)
            ->postJson('/api/calendar-events', $payload)
            ->assertForbidden();

        $manager = $this->user('calendar-manager@example.test');
        $this->grant($manager, ['calendar.view', 'calendar.manage']);
        $this->asWebClient($manager)
            ->getJson('/api/calendar-events/team-options')
            ->assertOk()
            ->assertJsonPath('data', []);
        $this->asWebClient($manager)
            ->getJson('/api/teams')
            ->assertForbidden();

        $created = $this->asWebClient($manager)
            ->postJson('/api/calendar-events', $payload)
            ->assertCreated()
            ->assertJsonPath('data.title', 'Teamavond')
            ->assertJsonPath('data.location_label', 'Utrecht');

        $eventId = (string) $created->json('data.id');
        $this->asWebClient($manager)
            ->deleteJson('/api/calendar-events/'.$eventId)
            ->assertOk();

        $this->assertSoftDeleted('calendar_events', ['id' => $eventId]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $manager->id,
            'action' => 'calendar_events.created',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $manager->id,
            'action' => 'calendar_events.deleted',
        ]);
    }

    public function test_operator_calendar_bootstrap_remains_available_with_calendar_view(): void
    {
        $operator = $this->user('calendar-operator@example.test');
        $this->grant(
            $operator,
            ['calendar.view'],
            canUseOperatorApp: true,
            canUseAdminApp: false,
        );

        $this->asWebClient($operator)
            ->getJson('/api/calendar-events')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
        $this->asOperatorClient($operator)
            ->getJson('/api/calendar-events')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Calendar Test User',
            'first_name' => 'Calendar',
            'last_name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function grant(
        User $user,
        array $permissionNames,
        bool $canUseOperatorApp = false,
        bool $canUseAdminApp = true,
    ): void {
        $role = Role::query()->create([
            'name' => 'calendar-test-'.strtolower((string) str()->ulid()),
            'display_name' => 'Calendar test role',
            'can_use_operator_app' => $canUseOperatorApp,
            'can_use_admin_app' => $canUseAdminApp,
        ]);

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'category' => 'calendar_management',
                    'display_name' => $permissionName,
                    'description' => 'Calendar test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }

        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function asWebClient(User $user): static
    {
        return $this->asClient($user, 'client:web', 'Calendar web client');
    }

    private function asOperatorClient(User $user): static
    {
        return $this->asClient($user, 'client:operator', 'Calendar operator client');
    }

    private function asClient(User $user, string $ability, string $name): static
    {
        $token = $user->createToken($name, ['*', $ability], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /**
     * @param  list<string>  $permissionNames
     * @return list<string>
     */
    private function rolePermissionNames(Role $role, array $permissionNames): array
    {
        return $role->permissions()
            ->whereIn('permissions.name', $permissionNames)
            ->pluck('permissions.name')
            ->sort()
            ->values()
            ->all();
    }
}
