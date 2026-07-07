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
        'address-book.view' => [
            'display_name' => 'Adresboek bekijken',
            'category' => 'address_book',
            'description' => 'Bekijk en doorzoek adresboekcontacten op naam, telefoonnummer en woonplaats.',
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
                    'category' => $permission['category'],
                    'display_name' => $permission['display_name'],
                    'description' => $permission['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        $adminRoleId = DB::table('roles')->where('name', 'system-administrator')->value('id');
        if (is_string($adminRoleId)) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'permission_id' => $permissionId,
                        'role_id' => $adminRoleId,
                    ],
                    ['created_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('name', array_keys($this->permissions))->delete();
    }

    private function idFor(string $table, string $column, string $value): string
    {
        $existing = DB::table($table)->where($column, $value)->value('id');

        return $existing !== null ? (string) $existing : (string) Str::ulid();
    }
};
