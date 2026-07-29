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
            ->where('name', 'system.logs.view')
            ->value('id');
        if (! is_string($permissionId)) {
            $permissionId = (string) Str::ulid();
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'system.logs.view'],
            [
                'id' => $permissionId,
                'display_name' => 'Systeemlogbestanden bekijken',
                'category' => 'system_configuration',
                'description' => 'Bekijk begrensde en geredigeerde Laravel-applicatielogs in webbeheer.',
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
