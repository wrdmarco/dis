<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $now = Carbon::now();
        $permissionId = $this->idFor('permissions', 'name', 'push.manage');

        DB::table('permissions')->updateOrInsert(
            ['name' => 'push.manage'],
            [
                'id' => $permissionId,
                'display_name' => 'Manage push notifications',
                'category' => 'push_management',
                'description' => 'Manage FCM tokens and send manual push notifications.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $roleIds = DB::table('roles')
            ->whereIn('name', ['system-administrator', 'national-coordinator', 'incident-coordinator'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(
                [
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ],
                ['created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'push.manage')->value('id');

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
