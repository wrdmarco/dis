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
        'users.delete' => ['display_name' => 'Gebruikers verwijderen', 'category' => 'user_management', 'description' => 'Verwijder gebruikers en gekoppelde operationele gegevens permanent.'],
        'users.credentials.manage' => ['display_name' => 'Inloggegevens beheren', 'category' => 'user_management', 'description' => 'Wijzig e-mailadres of wachtwoord van andere gebruikers.'],
        'users.mfa.reset' => ['display_name' => 'MFA van gebruikers resetten', 'category' => 'user_management', 'description' => 'Verwijder de bestaande MFA-registratie van een gebruiker.'],
        'users.sessions.revoke' => ['display_name' => 'Gebruikerssessies intrekken', 'category' => 'user_management', 'description' => 'Trek web-, mobiele en device-sessies van een gebruiker in.'],
        'users.login-lock.reset' => ['display_name' => 'Inlogblokkade opheffen', 'category' => 'user_management', 'description' => 'Hef een tijdelijke accountblokkade op.'],
        'roles.delete' => ['display_name' => 'Rollen verwijderen', 'category' => 'role_management', 'description' => 'Verwijder ongebruikte rollen permanent.'],
        'incidents.assigned.view' => ['display_name' => 'Eigen toegewezen incidenten bekijken', 'category' => 'incident_management', 'description' => 'Bekijk in de operator-app uitsluitend zelf toegewezen incidenten.'],
        'operational-map.view' => ['display_name' => 'Operationele kaart bekijken', 'category' => 'incident_management', 'description' => 'Bekijk de operationele kaart met meldkamers en incidentlocaties.'],
        'operational-map.pilot-homes.view' => ['display_name' => 'Globale woonplaatsen op kaart bekijken', 'category' => 'incident_management', 'description' => 'Toon globale woonplaatscoordinaten van piloten op de operationele kaart.'],
        'system.health.view' => ['display_name' => 'Systeemstatus bekijken', 'category' => 'system_configuration', 'description' => 'Bekijk queue, websocket, versie en servicestatus.'],
        'system.update.execute' => ['display_name' => 'Systeemupdate uitvoeren', 'category' => 'system_configuration', 'description' => 'Start een serverupdate.'],
        'system.reboot.execute' => ['display_name' => 'Server herstarten', 'category' => 'system_configuration', 'description' => 'Start een herstart van de DIS-server.'],
        'system.developer-access.manage' => ['display_name' => 'Developer-toegang beheren', 'category' => 'system_configuration', 'description' => 'Beheer developer API-sleutels en developerconfiguratie.'],
    ];

    public function up(): void
    {
        $now = Carbon::now();
        $legacyHealthId = DB::table('permissions')->where('name', 'system.health')->value('id');
        $legacyHealthRoleIds = is_string($legacyHealthId)
            ? DB::table('permission_role')->where('permission_id', $legacyHealthId)->pluck('role_id')->all()
            : [];

        foreach ($this->permissions as $name => $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => $this->idFor($name),
                    ...$permission,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table('permissions')->whereIn('name', array_keys($this->permissions))->pluck('id', 'name')->all();
        foreach ($legacyHealthRoleIds as $roleId) {
            $this->attach((string) $roleId, (string) $permissionIds['system.health.view'], $now);
        }

        if (is_string($legacyHealthId)) {
            DB::table('permission_role')->where('permission_id', $legacyHealthId)->delete();
            DB::table('permissions')->where('id', $legacyHealthId)->delete();
        }

        $roles = DB::table('roles')->whereIn('name', [
            'system-administrator',
            'national-coordinator',
            'incident-coordinator',
            'operator-pilot',
        ])->pluck('id', 'name')->all();

        if (isset($roles['system-administrator'])) {
            foreach ($permissionIds as $permissionId) {
                $this->attach((string) $roles['system-administrator'], (string) $permissionId, $now);
            }
        }

        foreach (['national-coordinator', 'incident-coordinator'] as $roleName) {
            if (! isset($roles[$roleName])) {
                continue;
            }
            $this->attach((string) $roles[$roleName], (string) $permissionIds['operational-map.view'], $now);
            $this->attach((string) $roles[$roleName], (string) $permissionIds['operational-map.pilot-homes.view'], $now);
        }

        if (isset($roles['operator-pilot'])) {
            $operatorRoleId = (string) $roles['operator-pilot'];
            $this->attach($operatorRoleId, (string) $permissionIds['incidents.assigned.view'], $now);
            $broadPermissionIds = DB::table('permissions')->whereIn('name', [
                'incidents.view',
                'incidents.dispatch.view',
                'status.view',
                'assets.view',
                'certifications.view',
            ])->pluck('id')->all();
            DB::table('permission_role')
                ->where('role_id', $operatorRoleId)
                ->whereIn('permission_id', $broadPermissionIds)
                ->delete();
        }
    }

    public function down(): void
    {
        // Security migrations are intentionally not made lossy on rollback.
    }

    private function idFor(string $name): string
    {
        return (string) (DB::table('permissions')->where('name', $name)->value('id') ?? Str::ulid());
    }

    private function attach(string $roleId, string $permissionId, Carbon $now): void
    {
        DB::table('permission_role')->updateOrInsert(
            ['role_id' => $roleId, 'permission_id' => $permissionId],
            ['created_at' => $now],
        );
    }
};
