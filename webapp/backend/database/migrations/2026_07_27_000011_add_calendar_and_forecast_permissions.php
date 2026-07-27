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
        'operational-weather.view' => [
            'display_name' => 'Operationeel weer bekijken',
            'category' => 'weather_configuration',
            'description' => 'Bekijk de operationele weersverwachting en bijbehorende lokale radar- en bliksembeelden.',
        ],
        'uav-forecast.view' => [
            'display_name' => 'UAV Forecast bekijken',
            'category' => 'weather_configuration',
            'description' => 'Bekijk de UAV Forecast met server-side vliegadvies en onderliggende weers- en ruimteweergegevens.',
        ],
        'calendar.view' => [
            'display_name' => 'Agenda bekijken',
            'category' => 'calendar_management',
            'description' => 'Bekijk algemene en voor de eigen teams zichtbare agenda-items.',
        ],
        'calendar.manage' => [
            'display_name' => 'Agenda beheren',
            'category' => 'calendar_management',
            'description' => 'Maak agenda-items aan en verwijder bestaande agenda-items. Vereist daarnaast Agenda bekijken.',
        ],
    ];

    /**
     * Upgrade grants follow an existing equivalent management permission.
     *
     * @var array<string, list<string>>
     */
    private array $compatibleExistingPermissions = [
        'operational-weather.view' => ['knmi.manage'],
        'uav-forecast.view' => ['knmi.manage'],
        'calendar.view' => ['settings.manage'],
        'calendar.manage' => ['settings.manage'],
    ];

    public function up(): void
    {
        $now = Carbon::now();
        $operatorRoleIds = DB::table('roles')
            ->where('can_use_operator_app', true)
            ->pluck('id');

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

            $roleIds = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereIn('permissions.name', $this->compatibleExistingPermissions[$name])
                ->pluck('permission_role.role_id');
            if ($name === 'calendar.view') {
                $roleIds = $roleIds->merge($operatorRoleIds);
            }

            foreach ($roleIds->unique()->values() as $roleId) {
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
