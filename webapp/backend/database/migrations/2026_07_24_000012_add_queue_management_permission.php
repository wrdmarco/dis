<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $permissionId = DB::table('permissions')
            ->where('name', 'system.queues.manage')
            ->value('id');
        if (! is_string($permissionId)) {
            $permissionId = (string) Str::ulid();
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'system.queues.manage'],
            [
                'id' => $permissionId,
                'display_name' => 'Wachtrijen bedienen',
                'category' => 'system_configuration',
                'description' => 'Start geschikte wachtende taken direct en herstart geschikte mislukte taken.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $administratorRoleId = DB::table('roles')
            ->where('name', 'system-administrator')
            ->value('id');
        if (is_string($administratorRoleId)) {
            DB::table('permission_role')->updateOrInsert(
                [
                    'role_id' => $administratorRoleId,
                    'permission_id' => $permissionId,
                ],
                ['created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        // Security permissions are intentionally not removed on rollback.
    }
};
