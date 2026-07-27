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
            ->where('name', 'deployment-requests.delete')
            ->value('id');
        if (! is_string($permissionId)) {
            $permissionId = (string) Str::ulid();
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'deployment-requests.delete'],
            [
                'id' => $permissionId,
                'display_name' => 'Aanvragen verwijderen',
                'category' => 'deployment_management',
                'description' => 'Verwijder losse aanvragen permanent. Aanvragen met een gekoppelde inzet worden via inzetbeheer verwijderd.',
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
        $permissionId = DB::table('permissions')
            ->where('name', 'deployment-requests.delete')
            ->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
