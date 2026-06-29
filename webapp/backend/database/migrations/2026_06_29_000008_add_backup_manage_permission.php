<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $now = Carbon::now();
        $permissionId = DB::table('permissions')->where('name', 'backups.manage')->value('id') ?: (string) Str::ulid();

        DB::table('permissions')->updateOrInsert(
            ['name' => 'backups.manage'],
            [
                'id' => $permissionId,
                'display_name' => 'Manage backups',
                'category' => 'system_configuration',
                'description' => 'Create, verify and restore system backups.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $systemAdminRoleId = DB::table('roles')->where('name', 'system-administrator')->value('id');
        if ($systemAdminRoleId !== null) {
            DB::table('permission_role')->updateOrInsert(
                [
                    'permission_id' => $permissionId,
                    'role_id' => $systemAdminRoleId,
                ],
                ['created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'backups.manage')->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
