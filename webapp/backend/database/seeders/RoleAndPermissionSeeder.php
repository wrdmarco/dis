<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RoleAndPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{display_name: string, category: string, description: string}>
     */
    private array $permissions = [
        'users.view' => ['display_name' => 'View users', 'category' => 'user_management', 'description' => 'View user records and operational identity state.'],
        'users.manage' => ['display_name' => 'Manage users', 'category' => 'user_management', 'description' => 'Create and update user records.'],
        'roles.manage' => ['display_name' => 'Manage roles', 'category' => 'role_management', 'description' => 'Assign roles and manage role membership.'],
        'teams.manage' => ['display_name' => 'Manage teams', 'category' => 'team_management', 'description' => 'Manage OCP, TUI and supporting team membership.'],
        'incidents.view' => ['display_name' => 'View incidents', 'category' => 'incident_management', 'description' => 'View incident records and timelines.'],
        'incidents.manage' => ['display_name' => 'Manage incidents', 'category' => 'incident_management', 'description' => 'Create, update, close and cancel incidents.'],
        'incidents.delete' => ['display_name' => 'Delete incidents', 'category' => 'incident_management', 'description' => 'Permanently delete incidents and related operational data.'],
        'dispatch.view' => ['display_name' => 'View dispatches', 'category' => 'dispatch_management', 'description' => 'View dispatch requests and recipient states.'],
        'dispatch.manage' => ['display_name' => 'Manage dispatches', 'category' => 'dispatch_management', 'description' => 'Create, send, cancel and escalate dispatch requests.'],
        'status.view' => ['display_name' => 'View statuses', 'category' => 'status_management', 'description' => 'View operational availability status.'],
        'status.override' => ['display_name' => 'Override statuses', 'category' => 'status_management', 'description' => 'Override availability with an auditable reason.'],
        'status.audit.view' => ['display_name' => 'View status audit', 'category' => 'status_management', 'description' => 'View audit log entries for availability status changes.'],
        'assets.view' => ['display_name' => 'View assets', 'category' => 'asset_management', 'description' => 'View operational assets and readiness.'],
        'assets.manage' => ['display_name' => 'Manage assets', 'category' => 'asset_management', 'description' => 'Create, update, assign and release assets.'],
        'certifications.view' => ['display_name' => 'View certifications', 'category' => 'certification_management', 'description' => 'View certification types and user certification state.'],
        'certifications.manage' => ['display_name' => 'Manage certifications', 'category' => 'certification_management', 'description' => 'Create certification types and manage user certifications.'],
        'audit.view' => ['display_name' => 'View audit logs', 'category' => 'audit_log_access', 'description' => 'Search and inspect audit logs.'],
        'updates.manage' => ['display_name' => 'Manage updates', 'category' => 'update_management', 'description' => 'Register Android versions and update policy.'],
        'push.manage' => ['display_name' => 'Manage push notifications', 'category' => 'push_management', 'description' => 'Manage FCM tokens and send manual push notifications.'],
        'settings.manage' => ['display_name' => 'Manage system settings', 'category' => 'system_configuration', 'description' => 'Update operational system settings.'],
        'system.health' => ['display_name' => 'View system health', 'category' => 'system_configuration', 'description' => 'View queue, websocket and service health.'],
        'backups.manage' => ['display_name' => 'Manage backups', 'category' => 'system_configuration', 'description' => 'Create, verify and restore system backups.'],
    ];

    /**
     * @var array<string, array{display_name: string, description: string, requires_two_factor: bool, can_use_operator_app: bool, can_use_admin_app: bool, permissions: list<string>}>
     */
    private array $roles = [
        'system-administrator' => [
            'display_name' => 'System Administrator',
            'description' => 'Full platform administration and security-sensitive configuration.',
            'requires_two_factor' => true,
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
            'permissions' => ['*'],
        ],
        'national-coordinator' => [
            'display_name' => 'National Coordinator',
            'description' => 'National operational coordination across incidents, teams and dispatches.',
            'requires_two_factor' => true,
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'teams.manage', 'incidents.view', 'incidents.manage',
                'dispatch.view', 'dispatch.manage', 'status.view', 'status.override',
                'assets.view', 'assets.manage', 'certifications.view', 'audit.view',
                'push.manage', 'system.health',
            ],
        ],
        'incident-coordinator' => [
            'display_name' => 'Incident Coordinator',
            'description' => 'Incident-level coordination and dispatch management.',
            'requires_two_factor' => true,
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'incidents.view', 'incidents.manage', 'dispatch.view',
                'dispatch.manage', 'status.view', 'assets.view', 'certifications.view',
                'push.manage',
            ],
        ],
        'operator-pilot' => [
            'display_name' => 'Operator / Pilot',
            'description' => 'Drone operator receiving dispatches and managing own operational status.',
            'requires_two_factor' => false,
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
            'permissions' => [
                'incidents.view', 'dispatch.view', 'status.view', 'assets.view',
                'certifications.view',
            ],
        ],
        'support-staff' => [
            'display_name' => 'Support Staff',
            'description' => 'Operational support for assets and certifications.',
            'requires_two_factor' => false,
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'assets.view', 'assets.manage', 'certifications.view',
                'certifications.manage', 'status.view',
            ],
        ],
        'auditor' => [
            'display_name' => 'Auditor',
            'description' => 'Read-only inspection of operational and audit records.',
            'requires_two_factor' => false,
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'incidents.view', 'dispatch.view', 'status.view',
                'assets.view', 'certifications.view', 'audit.view',
            ],
        ],
    ];

    public function run(): void
    {
        $now = Carbon::now();

        foreach ($this->permissions as $name => $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => $this->idFor('permissions', 'name', $name),
                    'category' => $permission['category'],
                    'display_name' => $permission['display_name'],
                    'description' => $permission['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        foreach ($this->roles as $name => $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => $this->idFor('roles', 'name', $name),
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                    'requires_two_factor' => $role['requires_two_factor'],
                    'can_use_operator_app' => $role['can_use_operator_app'],
                    'can_use_admin_app' => $role['can_use_admin_app'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'name')->all();
        $roleIds = DB::table('roles')->pluck('id', 'name')->all();

        foreach ($this->roles as $roleName => $role) {
            $assignedPermissions = $role['permissions'] === ['*']
                ? array_keys($permissionIds)
                : $role['permissions'];

            foreach ($assignedPermissions as $permissionName) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'permission_id' => $permissionIds[$permissionName],
                        'role_id' => $roleIds[$roleName],
                    ],
                    ['created_at' => $now],
                );
            }
        }
    }

    private function idFor(string $table, string $column, string $value): string
    {
        $existing = DB::table($table)->where($column, $value)->value('id');

        return $existing !== null ? (string) $existing : (string) Str::ulid();
    }
}
