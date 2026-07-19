<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        $permissionId = (string) (DB::table('permissions')
            ->where('name', 'wallboards.manage')
            ->value('id') ?? Str::ulid());

        DB::table('permissions')->updateOrInsert(
            ['name' => 'wallboards.manage'],
            [
                'id' => $permissionId,
                'display_name' => 'Wallboards beheren',
                'category' => 'system_configuration',
                'description' => 'Beheer wallboardindelingen, koppelcodes en afzonderlijke wallboardsessies.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $administratorRoleId = DB::table('roles')
            ->where('name', 'system-administrator')
            ->value('id');

        if (is_string($administratorRoleId)) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $administratorRoleId, 'permission_id' => $permissionId],
                ['created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'wallboards.manage')->value('id');
        if (! is_string($permissionId)) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
