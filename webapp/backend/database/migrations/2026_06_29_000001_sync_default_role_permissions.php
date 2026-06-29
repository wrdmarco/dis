<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
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
     * @var array<string, list<string>>
     */
    private array $rolePermissions = [
        'system-administrator' => ['*'],
        'national-coordinator' => [
            'users.view', 'teams.manage', 'incidents.view', 'incidents.manage',
            'dispatch.view', 'dispatch.manage', 'status.view', 'status.override',
            'assets.view', 'assets.manage', 'certifications.view', 'audit.view',
            'push.manage', 'system.health',
        ],
        'incident-coordinator' => [
            'users.view', 'incidents.view', 'incidents.manage', 'dispatch.view',
            'dispatch.manage', 'status.view', 'assets.view', 'certifications.view',
            'push.manage',
        ],
        'operator-pilot' => [
            'incidents.view', 'dispatch.view', 'status.view', 'assets.view',
            'certifications.view',
        ],
        'support-staff' => [
            'users.view', 'assets.view', 'assets.manage', 'certifications.view',
            'certifications.manage', 'status.view',
        ],
        'auditor' => [
            'users.view', 'incidents.view', 'dispatch.view', 'status.view',
            'assets.view', 'certifications.view', 'audit.view',
        ],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->permissions as $name => $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => $this->idFor('permissions', 'name', $name),
                    'display_name' => $permission['display_name'],
                    'category' => $permission['category'],
                    'description' => $permission['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'name')->all();
        $roleIds = DB::table('roles')->whereIn('name', array_keys($this->rolePermissions))->pluck('id', 'name')->all();

        foreach ($this->rolePermissions as $roleName => $permissions) {
            if (! array_key_exists($roleName, $roleIds)) {
                continue;
            }

            $assignedPermissions = $permissions === ['*'] ? array_keys($permissionIds) : $permissions;

            foreach ($assignedPermissions as $permissionName) {
                if (! array_key_exists($permissionName, $permissionIds)) {
                    continue;
                }

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

    public function down(): void
    {
        // This migration repairs additive role permissions on existing systems.
        // Rolling it back should not remove admin-managed permission choices.
    }

    private function idFor(string $table, string $column, string $value): string
    {
        $existing = DB::table($table)->where($column, $value)->value('id');

        return $existing !== null ? (string) $existing : (string) Str::ulid();
    }
};
