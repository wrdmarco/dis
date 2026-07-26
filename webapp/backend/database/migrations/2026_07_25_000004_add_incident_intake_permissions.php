<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, array{display_name: string, description: string}> */
    private array $permissions = [
        'intakes.priority.override' => [
            'display_name' => 'Uitvraagadvies overschrijven',
            'description' => 'Stel gemotiveerd een andere prioriteit of inzetkeuze vast dan het serveradvies.',
        ],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->permissions as $name => $definition) {
            $id = (string) (DB::table('permissions')->where('name', $name)->value('id') ?? Str::ulid());
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => $id,
                    'category' => 'incident_management',
                    'display_name' => $definition['display_name'],
                    'description' => $definition['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $roleIds = DB::table('roles')
                ->whereIn('name', [
                    'system-administrator',
                    'national-coordinator',
                    'incident-coordinator',
                ])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $id],
                    ['created_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
