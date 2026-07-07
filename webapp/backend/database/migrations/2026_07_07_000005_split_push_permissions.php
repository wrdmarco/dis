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
    private array $newPermissions = [
        'settings.push.tokens.manage' => [
            'display_name' => 'Push tokens beheren',
            'category' => 'system_configuration',
            'description' => 'Bekijk, activeer en trek FCM tokens/devices in. Geeft geen recht om handmatige pushmeldingen te versturen.',
        ],
        'settings.push.manual.send' => [
            'display_name' => 'Handmatige pushmeldingen versturen',
            'category' => 'system_configuration',
            'description' => 'Stuur handmatige pushmeldingen naar geselecteerde teams, rollen of gebruikers. Geeft geen recht om tokens in te trekken.',
        ],
    ];

    public function up(): void
    {
        $now = Carbon::now();
        $oldPermissionId = DB::table('permissions')->where('name', 'push.manage')->value('id');
        $roleIds = is_string($oldPermissionId)
            ? DB::table('permission_role')->where('permission_id', $oldPermissionId)->pluck('role_id')->all()
            : [];

        foreach ($this->newPermissions as $name => $permission) {
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

        $newPermissionIds = DB::table('permissions')->whereIn('name', array_keys($this->newPermissions))->pluck('id')->all();
        foreach ($roleIds as $roleId) {
            foreach ($newPermissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'permission_id' => $permissionId,
                        'role_id' => $roleId,
                    ],
                    ['created_at' => $now],
                );
            }
        }

        if (is_string($oldPermissionId)) {
            DB::table('permission_role')->where('permission_id', $oldPermissionId)->delete();
            DB::table('permissions')->where('id', $oldPermissionId)->delete();
        }
    }

    public function down(): void
    {
        $now = Carbon::now();
        $newPermissionIds = DB::table('permissions')->whereIn('name', array_keys($this->newPermissions))->pluck('id')->all();
        $roleIds = DB::table('permission_role')->whereIn('permission_id', $newPermissionIds)->pluck('role_id')->unique()->all();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'push.manage'],
            [
                'id' => $this->idFor('permissions', 'name', 'push.manage'),
                'display_name' => 'Pushmeldingen beheren',
                'category' => 'push_management',
                'description' => 'Bekijk FCM tokens, trek tokens in en verstuur handmatige pushmeldingen.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $oldPermissionId = DB::table('permissions')->where('name', 'push.manage')->value('id');
        if (is_string($oldPermissionId)) {
            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'permission_id' => $oldPermissionId,
                        'role_id' => $roleId,
                    ],
                    ['created_at' => $now],
                );
            }
        }

        DB::table('permission_role')->whereIn('permission_id', $newPermissionIds)->delete();
        DB::table('permissions')->whereIn('id', $newPermissionIds)->delete();
    }

    private function idFor(string $table, string $column, string $value): string
    {
        $existing = DB::table($table)->where($column, $value)->value('id');

        return $existing !== null ? (string) $existing : (string) Str::ulid();
    }
};
