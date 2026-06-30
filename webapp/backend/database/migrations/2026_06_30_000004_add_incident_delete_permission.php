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
        $permissionId = $this->idFor('permissions', 'name', 'incidents.delete');

        DB::table('permissions')->updateOrInsert(
            ['name' => 'incidents.delete'],
            [
                'id' => $permissionId,
                'display_name' => 'Delete incidents',
                'category' => 'incident_management',
                'description' => 'Permanently delete incidents and related operational data.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $roleId = DB::table('roles')->where('name', 'system-administrator')->value('id');
        if ($roleId === null) {
            return;
        }

        DB::table('permission_role')->updateOrInsert(
            [
                'permission_id' => $permissionId,
                'role_id' => (string) $roleId,
            ],
            ['created_at' => $now],
        );
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'incidents.delete')->value('id');
        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }

    private function idFor(string $table, string $column, string $value): string
    {
        $existing = DB::table($table)->where($column, $value)->value('id');

        return $existing !== null ? (string) $existing : (string) Str::ulid();
    }
};
