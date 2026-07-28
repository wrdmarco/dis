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
        'calendar.register' => [
            'display_name' => 'Inschrijven voor agenda-items',
            'category' => 'calendar_management',
            'description' => 'Schrijf uzelf in voor een agenda-item of annuleer uw eigen inschrijving. Vereist daarnaast Agenda bekijken.',
        ],
        'calendar.groups.manage' => [
            'display_name' => 'Agendagroepen beheren',
            'category' => 'calendar_management',
            'description' => 'Beheer agendagroepen en hun directe gebruikers- en teamkoppelingen.',
        ],
        'calendar.registrations.view' => [
            'display_name' => 'Agenda-inschrijvingen bekijken',
            'category' => 'calendar_management',
            'description' => 'Bekijk de deelnemerslijst van agenda-items.',
        ],
        'calendar.registrations.manage' => [
            'display_name' => 'Agenda-inschrijvingen beheren',
            'category' => 'calendar_management',
            'description' => 'Schrijf andere gebruikers in voor agenda-items of annuleer hun inschrijving.',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $roleGrants = [
        'calendar.register' => [
            'system-administrator',
            'national-coordinator',
            'deployment-coordinator',
            'operator-pilot',
        ],
        'calendar.groups.manage' => ['system-administrator'],
        'calendar.registrations.view' => ['system-administrator'],
        'calendar.registrations.manage' => ['system-administrator'],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->permissions as $name => $permission) {
            $permissionId = (string) (DB::table('permissions')
                ->where('name', $name)
                ->value('id') ?? Str::ulid());

            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => $permissionId,
                    'display_name' => $permission['display_name'],
                    'category' => $permission['category'],
                    'description' => $permission['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $roleIds = DB::table('roles')
                ->whereIn('name', $this->roleGrants[$name])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys($this->permissions))
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
