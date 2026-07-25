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
        'expiry.view' => [
            'display_name' => 'Verloopoverzicht bekijken',
            'category' => 'expiry_management',
            'description' => 'Open het verloopoverzicht. Asset- en certificaatgegevens blijven afzonderlijk beperkt door de bijbehorende bekijkrechten.',
        ],
        'vacations.view' => [
            'display_name' => 'Vakantieplanning bekijken',
            'category' => 'vacation_management',
            'description' => 'Bekijk de vakantieplanning en ingestelde beschikbaarheid van andere gebruikers.',
        ],
        'vacations.manage' => [
            'display_name' => 'Vakantieplanning beheren',
            'category' => 'vacation_management',
            'description' => 'Maak, wijzig en verwijder vakantieperiodes namens andere gebruikers.',
        ],
        'forms.manage' => [
            'display_name' => 'Formulieren beheren',
            'category' => 'form_configuration',
            'description' => 'Beheer de opbouw en veldinstellingen van operationele formulieren.',
        ],
        'knmi.manage' => [
            'display_name' => 'KNMI-gegevens beheren',
            'category' => 'weather_configuration',
            'description' => 'Bekijk en wijzig KNMI-bronconfiguratie en start handmatige datasetupdates.',
        ],
        'branding.manage' => [
            'display_name' => 'Branding en berichtteksten beheren',
            'category' => 'branding_configuration',
            'description' => 'Beheer huisstijl, logo, mail- en pushberichtteksten zonder toegang tot overige systeeminstellingen.',
        ],
        'system.routing.view' => [
            'display_name' => 'Routeringsstatus bekijken',
            'category' => 'system_configuration',
            'description' => 'Bekijk de status en voortgang van lokale OSRM-routeringsdata zonder bewerkingen te starten.',
        ],
        'system.queues.view' => [
            'display_name' => 'Wachtrijen bekijken',
            'category' => 'system_configuration',
            'description' => 'Bekijk wachtende, actieve en mislukte achtergrondtaken zonder ze te bedienen.',
        ],
    ];

    /**
     * New permissions are granted only to roles that already had equivalent access.
     *
     * @var array<string, list<string>>
     */
    private array $compatibleExistingPermissions = [
        'expiry.view' => ['assets.view', 'certifications.view'],
        'vacations.view' => ['users.view'],
        'vacations.manage' => ['users.manage'],
        'forms.manage' => ['settings.manage'],
        'knmi.manage' => ['settings.manage'],
        'branding.manage' => ['settings.manage'],
        'system.routing.view' => ['system.health.view', 'system.routing.manage'],
        'system.queues.view' => ['system.health.view', 'system.queues.manage'],
    ];

    /**
     * @var array<string, array{up: string, down: string}>
     */
    private array $updatedDescriptions = [
        'settings.manage' => [
            'up' => 'Wijzig technische systeem-, integratie-, mail- en beveiligingsinstellingen. Formulieren, KNMI en branding hebben afzonderlijke rechten.',
            'down' => 'Wijzig technische instellingen, formulieren, branding, mail en systeemconfiguratie.',
        ],
        'system.health.view' => [
            'up' => 'Bekijk websocket-, versie- en servicestatus zonder beheersacties uit te voeren. Wachtrijen en routering hebben afzonderlijke bekijkrechten.',
            'down' => 'Bekijk queue, websocket, versie en servicestatus zonder beheersacties uit te voeren.',
        ],
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

            $roleIds = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->whereIn('permissions.name', $this->compatibleExistingPermissions[$name])
                ->pluck('permission_role.role_id')
                ->unique()
                ->values();

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now],
                );
            }
        }

        foreach ($this->updatedDescriptions as $name => $descriptions) {
            DB::table('permissions')
                ->where('name', $name)
                ->update(['description' => $descriptions['up'], 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $now = Carbon::now();
        foreach ($this->updatedDescriptions as $name => $descriptions) {
            DB::table('permissions')
                ->where('name', $name)
                ->update(['description' => $descriptions['down'], 'updated_at' => $now]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys($this->permissions))
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
