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
            ->where('name', 'product-requests.update-any')
            ->value('id');
        if (! is_string($permissionId)) {
            $permissionId = (string) Str::ulid();
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'product-requests.update-any'],
            [
                'id' => $permissionId,
                'display_name' => 'Alle verzoeken aanpassen',
                'category' => 'product_request_management',
                'description' => 'Wijzig type, titel en omschrijving van ieder niet-afgesloten verzoek. De aanvrager blijft ongewijzigd. Vereist daarnaast Verzoeken bekijken.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $roleIds = DB::table('roles')
            ->whereIn('name', ['request-handler', 'system-administrator'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(
                [
                    'role_id' => (string) $roleId,
                    'permission_id' => $permissionId,
                ],
                ['created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', 'product-requests.update-any')
            ->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
