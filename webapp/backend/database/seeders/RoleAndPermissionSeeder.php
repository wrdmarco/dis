<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RoleAndPermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{display_name: string, category: string, description: string}>
     */
    private array $permissions = [
        'users.view' => ['display_name' => 'Gebruikers bekijken', 'category' => 'user_management', 'description' => 'Bekijk gebruikers, teams, rollen, devices en operationele gebruikersstatus. Eigen profiel bekijken is standaard en heeft geen aparte permissie nodig.'],
        'users.manage' => ['display_name' => 'Gebruikers beheren', 'category' => 'user_management', 'description' => 'Maak gebruikers aan, wijzig accounts, koppel rollen/teams en beheer device-limieten.'],
        'address-book.view' => ['display_name' => 'Adresboek bekijken', 'category' => 'address_book', 'description' => 'Bekijk en doorzoek adresboekcontacten op naam, telefoonnummer en woonplaats.'],
        'roles.manage' => ['display_name' => 'Rollen en rechten beheren', 'category' => 'role_management', 'description' => 'Maak rollen aan, wijzig rolrechten en bepaal toegang tot operator- en admin-app.'],
        'teams.manage' => ['display_name' => 'Teams beheren', 'category' => 'team_management', 'description' => 'Beheer OCP, TUI, alarmeerteams en teamkoppelingen. TUI blijft een subset van OCP.'],
        'incidents.view' => ['display_name' => 'Incidenten bekijken', 'category' => 'incident_management', 'description' => 'Bekijk incidenten, details, tijdlijn en rapportstatus. Dit geeft geen recht om mensen te alarmeren of opkomst te bedienen.'],
        'incidents.manage' => ['display_name' => 'Incidentregistratie beheren', 'category' => 'incident_management', 'description' => 'Maak en wijzig incidenten, beheer status, kladblokregels, afsluiten en annuleren. Alarmeren en opkomst vallen onder incidentalarmering.'],
        'incidents.delete' => ['display_name' => 'Incidenten verwijderen', 'category' => 'incident_management', 'description' => 'Verwijder incidenten en gekoppelde operationele gegevens permanent. Gebruik alleen voor beheer.'],
        'incidents.dispatch.view' => ['display_name' => 'Incidentalarmering bekijken', 'category' => 'incident_management', 'description' => 'Bekijk vooraankondigingen, alarmeringen, gealarmeerde teams/personen, reacties, opkomststatus en dispatch-statistieken bij incidenten.'],
        'incidents.dispatch.manage' => ['display_name' => 'Incidentalarmering bedienen', 'category' => 'incident_management', 'description' => 'Bedien het alarmeringsproces rond een incident: proefalarm, vooraankondigen, alarmeren, nadere info, opschalen, heralarmeren, annuleren, opkomst corrigeren en locatieverzoeken sturen.'],
        'status.view' => ['display_name' => 'Operationele status bekijken', 'category' => 'status_management', 'description' => 'Bekijk beschikbaarheid, online/offline devices en actuele inzetbaarheid.'],
        'status.override' => ['display_name' => 'Operationele status aanpassen', 'category' => 'status_management', 'description' => 'Wijzig beschikbaarheid of status namens een gebruiker met auditreden.'],
        'status.audit.view' => ['display_name' => 'Status-audit bekijken', 'category' => 'status_management', 'description' => 'Bekijk auditregels van beschikbaarheids- en statuswijzigingen.'],
        'assets.view' => ['display_name' => 'Middelen bekijken', 'category' => 'asset_management', 'description' => 'Bekijk drones, voertuigen, koppelingen en gereedheid van middelen.'],
        'assets.manage' => ['display_name' => 'Middelen beheren', 'category' => 'asset_management', 'description' => 'Maak middelen aan, wijzig ze, koppel ze aan gebruikers en geef ze vrij.'],
        'certifications.view' => ['display_name' => 'Certificaten bekijken', 'category' => 'certification_management', 'description' => 'Bekijk certificaattypen, geldigheid en gebruikerscertificaten.'],
        'certifications.manage' => ['display_name' => 'Certificaten beheren', 'category' => 'certification_management', 'description' => 'Maak certificaattypen aan en beheer certificaten van gebruikers.'],
        'audit.view' => ['display_name' => 'Auditlog bekijken', 'category' => 'audit_log_access', 'description' => 'Zoek en inspecteer auditlogs van beheer- en incidentacties.'],
        'updates.manage' => ['display_name' => 'App-updates beheren', 'category' => 'update_management', 'description' => 'Registreer Android/iOS versies en bepaal updatebeleid.'],
        'settings.push.tokens.manage' => ['display_name' => 'Push tokens beheren', 'category' => 'system_configuration', 'description' => 'Bekijk, activeer en trek FCM tokens/devices in. Geeft geen recht om handmatige pushmeldingen te versturen.'],
        'settings.push.manual.send' => ['display_name' => 'Handmatige pushmeldingen versturen', 'category' => 'system_configuration', 'description' => 'Stuur handmatige pushmeldingen naar geselecteerde teams, rollen of gebruikers. Geeft geen recht om tokens in te trekken.'],
        'settings.manage' => ['display_name' => 'Systeeminstellingen beheren', 'category' => 'system_configuration', 'description' => 'Wijzig technische instellingen, formulieren, branding, mail en systeemconfiguratie.'],
        'system.health' => ['display_name' => 'Systeemstatus bekijken', 'category' => 'system_configuration', 'description' => 'Bekijk queue, websocket, versie, updater en servicestatus.'],
        'backups.manage' => ['display_name' => 'Backups beheren', 'category' => 'system_configuration', 'description' => 'Maak, verifieer, herstel en configureer automatische backups.'],
    ];

    /**
     * @var array<string, array{display_name: string, description: string, requires_two_factor: bool, can_use_operator_app: bool, can_use_admin_app: bool, permissions: list<string>}>
     */
    private array $roles = [
        'system-administrator' => [
            'display_name' => 'System Administrator',
            'description' => 'Full platform administration and security-sensitive configuration.',
            'requires_two_factor' => true,
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
            'permissions' => ['*'],
        ],
        'national-coordinator' => [
            'display_name' => 'National Coordinator',
            'description' => 'National operational coordination across incidents, teams and dispatches.',
            'requires_two_factor' => true,
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'teams.manage', 'incidents.view', 'incidents.manage',
                'incidents.dispatch.view', 'incidents.dispatch.manage', 'status.view', 'status.override',
                'assets.view', 'assets.manage', 'certifications.view', 'audit.view',
                'address-book.view', 'settings.push.tokens.manage', 'settings.push.manual.send', 'system.health',
            ],
        ],
        'incident-coordinator' => [
            'display_name' => 'Incident Coordinator',
            'description' => 'Incident-level coordination and dispatch management.',
            'requires_two_factor' => true,
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'incidents.view', 'incidents.manage', 'incidents.dispatch.view',
                'incidents.dispatch.manage', 'status.view', 'assets.view', 'certifications.view',
                'address-book.view', 'settings.push.tokens.manage', 'settings.push.manual.send',
            ],
        ],
        'operator-pilot' => [
            'display_name' => 'Operator / Pilot',
            'description' => 'Drone operator receiving dispatches and managing own operational status.',
            'requires_two_factor' => false,
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
            'permissions' => [
                'incidents.view', 'incidents.dispatch.view', 'status.view', 'assets.view',
                'certifications.view',
            ],
        ],
        'support-staff' => [
            'display_name' => 'Support Staff',
            'description' => 'Operational support for assets and certifications.',
            'requires_two_factor' => false,
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'assets.view', 'assets.manage', 'certifications.view',
                'certifications.manage', 'status.view',
            ],
        ],
        'auditor' => [
            'display_name' => 'Auditor',
            'description' => 'Read-only inspection of operational and audit records.',
            'requires_two_factor' => false,
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'incidents.view', 'incidents.dispatch.view', 'status.view',
                'assets.view', 'certifications.view', 'address-book.view', 'audit.view',
            ],
        ],
    ];

    public function run(): void
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

        foreach ($this->roles as $name => $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => $this->idFor('roles', 'name', $name),
                    'display_name' => $role['display_name'],
                    'description' => $role['description'],
                    'requires_two_factor' => $role['requires_two_factor'],
                    'can_use_operator_app' => $role['can_use_operator_app'],
                    'can_use_admin_app' => $role['can_use_admin_app'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'name')->all();
        $roleIds = DB::table('roles')->pluck('id', 'name')->all();

        foreach ($this->roles as $roleName => $role) {
            $assignedPermissions = $role['permissions'] === ['*']
                ? array_keys($permissionIds)
                : $role['permissions'];

            foreach ($assignedPermissions as $permissionName) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'permission_id' => $permissionIds[$permissionName],
                        'role_id' => $roleIds[$roleName],
                    ],
                    ['created_at' => $now],
                );
            }
        }
    }

    private function idFor(string $table, string $column, string $value): string
    {
        $existing = DB::table($table)->where($column, $value)->value('id');

        return $existing !== null ? (string) $existing : (string) Str::ulid();
    }
}
