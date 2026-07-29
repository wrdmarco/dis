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
        'users.delete' => ['display_name' => 'Gebruikers verwijderen', 'category' => 'user_management', 'description' => 'Verwijder gebruikers en hun gekoppelde operationele gegevens permanent. Dit recht is standaard alleen voor systeembeheerders.'],
        'users.credentials.manage' => ['display_name' => 'Inloggegevens beheren', 'category' => 'user_management', 'description' => 'Wijzig e-mailadres of wachtwoord van andere gebruikers. Dit recht is losgekoppeld van algemeen gebruikersbeheer.'],
        'users.mfa.reset' => ['display_name' => 'MFA van gebruikers resetten', 'category' => 'user_management', 'description' => 'Verwijder de bestaande MFA-registratie zodat een gebruiker opnieuw moet koppelen.'],
        'users.sessions.revoke' => ['display_name' => 'Gebruikerssessies intrekken', 'category' => 'user_management', 'description' => 'Trek web-, mobiele en device-sessies van een gebruiker direct in.'],
        'users.login-lock.reset' => ['display_name' => 'Inlogblokkade opheffen', 'category' => 'user_management', 'description' => 'Hef een tijdelijke accountblokkade na mislukte inlogpogingen op.'],
        'address-book.view' => ['display_name' => 'Adresboek bekijken', 'category' => 'address_book', 'description' => 'Bekijk en doorzoek adresboekcontacten op naam, telefoonnummer en woonplaats.'],
        'roles.manage' => ['display_name' => 'Rollen en rechten beheren', 'category' => 'role_management', 'description' => 'Maak rollen aan, wijzig rolrechten en bepaal toegang tot de Operator-app en webbeheer.'],
        'roles.delete' => ['display_name' => 'Rollen verwijderen', 'category' => 'role_management', 'description' => 'Verwijder ongebruikte rollen permanent. Dit recht is standaard alleen voor systeembeheerders.'],
        'teams.manage' => ['display_name' => 'Teams beheren', 'category' => 'team_management', 'description' => 'Beheer OCP, TUI, alarmeerteams en teamkoppelingen. TUI blijft een subset van OCP.'],
        'deployments.view' => ['display_name' => 'Inzetten bekijken', 'category' => 'deployment_management', 'description' => 'Bekijk inzetten, details, tijdlijn en rapportstatus. Dit geeft geen recht om mensen te alarmeren of opkomst te bedienen.'],
        'deployments.assigned.view' => ['display_name' => 'Eigen toegewezen inzetten bekijken', 'category' => 'deployment_management', 'description' => 'Bekijk in de operator-app uitsluitend inzetten waarvoor de gebruiker zelf ontvanger of geaccepteerde opkomer is.'],
        'deployments.manage' => ['display_name' => 'Inzetregistratie beheren', 'category' => 'deployment_management', 'description' => 'Maak en wijzig inzetten, beheer status, kladblokregels, afsluiten en annuleren. Alarmeren en opkomst vallen onder inzetalarmering.'],
        'deployment-requests.priority.override' => ['display_name' => 'Uitvraagadvies overschrijven', 'category' => 'deployment_management', 'description' => 'Stel gemotiveerd een andere prioriteit of inzetkeuze vast dan het serveradvies.'],
        'deployment-requests.delete' => ['display_name' => 'Aanvragen verwijderen', 'category' => 'deployment_management', 'description' => 'Verwijder losse aanvragen permanent. Aanvragen met een gekoppelde inzet worden via inzetbeheer verwijderd.'],
        'deployments.delete' => ['display_name' => 'Inzetten verwijderen', 'category' => 'deployment_management', 'description' => 'Verwijder inzetten en gekoppelde operationele gegevens permanent. Gebruik alleen voor beheer.'],
        'deployments.dispatch.view' => ['display_name' => 'Inzetalarmering bekijken', 'category' => 'deployment_management', 'description' => 'Bekijk vooraankondigingen, alarmeringen, gealarmeerde teams/personen, reacties, opkomststatus en dispatch-statistieken bij inzetten.'],
        'deployments.dispatch.manage' => ['display_name' => 'Inzetalarmering bedienen', 'category' => 'deployment_management', 'description' => 'Bedien het alarmeringsproces rond een inzet: proefalarm, vooraankondigen, alarmeren, nadere info, opschalen, heralarmeren, annuleren, opkomst corrigeren en locatieverzoeken sturen.'],
        'status.view' => ['display_name' => 'Operationele status bekijken', 'category' => 'status_management', 'description' => 'Bekijk beschikbaarheid, wekelijkse beschikbaarheidsplanning, online/offline devices en actuele inzetbaarheid.'],
        'status.override' => ['display_name' => 'Operationele status aanpassen', 'category' => 'status_management', 'description' => 'Wijzig beschikbaarheid of status namens een gebruiker met auditreden.'],
        'status.audit.view' => ['display_name' => 'Status-audit bekijken', 'category' => 'status_management', 'description' => 'Bekijk auditregels van beschikbaarheids- en statuswijzigingen.'],
        'vacations.view' => ['display_name' => 'Vakantieplanning bekijken', 'category' => 'vacation_management', 'description' => 'Bekijk de vakantieplanning en ingestelde beschikbaarheid van andere gebruikers.'],
        'vacations.manage' => ['display_name' => 'Vakantieplanning beheren', 'category' => 'vacation_management', 'description' => 'Maak, wijzig en verwijder vakantieperiodes namens andere gebruikers.'],
        'assets.view' => ['display_name' => 'Middelen bekijken', 'category' => 'asset_management', 'description' => 'Bekijk drones, voertuigen, koppelingen en gereedheid van middelen.'],
        'assets.manage' => ['display_name' => 'Middelen beheren', 'category' => 'asset_management', 'description' => 'Maak middelen aan, wijzig ze, koppel ze aan gebruikers en geef ze vrij.'],
        'certifications.view' => ['display_name' => 'Certificaten bekijken', 'category' => 'certification_management', 'description' => 'Bekijk certificaattypen, geldigheid en gebruikerscertificaten.'],
        'certifications.manage' => ['display_name' => 'Certificaten beheren', 'category' => 'certification_management', 'description' => 'Maak certificaattypen aan en beheer certificaten van gebruikers.'],
        'expiry.view' => ['display_name' => 'Verloopoverzicht bekijken', 'category' => 'expiry_management', 'description' => 'Open het verloopoverzicht. Asset- en certificaatgegevens blijven afzonderlijk beperkt door de bijbehorende bekijkrechten.'],
        'audit.view' => ['display_name' => 'Auditlog bekijken', 'category' => 'audit_log_access', 'description' => 'Zoek en inspecteer auditlogs van beheer- en inzetacties.'],
        'updates.manage' => ['display_name' => 'App-updates beheren', 'category' => 'update_management', 'description' => 'Registreer Android/iOS versies en bepaal updatebeleid.'],
        'settings.push.tokens.manage' => ['display_name' => 'Push tokens beheren', 'category' => 'system_configuration', 'description' => 'Bekijk, activeer en trek FCM tokens/devices in. Geeft geen recht om handmatige pushmeldingen te versturen.'],
        'settings.push.manual.send' => ['display_name' => 'Handmatige pushmeldingen versturen', 'category' => 'system_configuration', 'description' => 'Stuur handmatige pushmeldingen naar geselecteerde teams, rollen of gebruikers. Geeft geen recht om tokens in te trekken.'],
        'settings.manage' => ['display_name' => 'Systeeminstellingen beheren', 'category' => 'system_configuration', 'description' => 'Wijzig technische systeem-, integratie-, mail- en beveiligingsinstellingen. Formulieren, KNMI en branding hebben afzonderlijke rechten.'],
        'forms.manage' => ['display_name' => 'Formulieren beheren', 'category' => 'form_configuration', 'description' => 'Beheer de opbouw en veldinstellingen van operationele formulieren.'],
        'knmi.manage' => ['display_name' => 'KNMI-gegevens beheren', 'category' => 'weather_configuration', 'description' => 'Bekijk en wijzig KNMI-bronconfiguratie en start handmatige datasetupdates.'],
        'operational-weather.view' => ['display_name' => 'Operationeel weer bekijken', 'category' => 'weather_configuration', 'description' => 'Bekijk de operationele weersverwachting en bijbehorende lokale radar- en bliksembeelden.'],
        'uav-forecast.view' => ['display_name' => 'UAV Forecast bekijken', 'category' => 'weather_configuration', 'description' => 'Bekijk de UAV Forecast met server-side vliegadvies en onderliggende weers- en ruimteweergegevens.'],
        'calendar.view' => ['display_name' => 'Agenda bekijken', 'category' => 'calendar_management', 'description' => 'Bekijk agenda-items voor de dynamische agendagroepen waarvan de gebruiker lid is.'],
        'calendar.manage' => ['display_name' => 'Agenda beheren', 'category' => 'calendar_management', 'description' => 'Maak agenda-items aan, pas bestaande agenda-items aan en verwijder ze. Vereist daarnaast Agenda bekijken.'],
        'calendar.register' => ['display_name' => 'Inschrijven voor agenda-items', 'category' => 'calendar_management', 'description' => 'Schrijf uzelf in voor een agenda-item of annuleer uw eigen inschrijving. Vereist daarnaast Agenda bekijken.'],
        'calendar.groups.manage' => ['display_name' => 'Agendagroepen beheren', 'category' => 'calendar_management', 'description' => 'Beheer agendagroepen en hun directe gebruikers- en teamkoppelingen.'],
        'calendar.registrations.view' => ['display_name' => 'Agenda-inschrijvingen bekijken', 'category' => 'calendar_management', 'description' => 'Bekijk de deelnemerslijst van agenda-items.'],
        'calendar.registrations.manage' => ['display_name' => 'Agenda-inschrijvingen beheren', 'category' => 'calendar_management', 'description' => 'Schrijf andere gebruikers in voor agenda-items of annuleer hun inschrijving.'],
        'product-requests.view' => ['display_name' => 'Verzoeken bekijken', 'category' => 'product_request_management', 'description' => 'Bekijk alle ingediende feature requests, wijzigingsverzoeken en bugmeldingen.'],
        'product-requests.create' => ['display_name' => 'Verzoeken indienen', 'category' => 'product_request_management', 'description' => 'Dien een feature request, wijzigingsverzoek of bugmelding in. Vereist daarnaast Verzoeken bekijken.'],
        'product-requests.update-own' => ['display_name' => 'Eigen verzoeken aanpassen', 'category' => 'product_request_management', 'description' => 'Wijzig de inhoud van eigen verzoeken zolang deze niet zijn opgelost of afgewezen. Vereist daarnaast Verzoeken bekijken.'],
        'product-requests.update-any' => ['display_name' => 'Alle verzoeken aanpassen', 'category' => 'product_request_management', 'description' => 'Wijzig type, titel en omschrijving van ieder niet-afgesloten verzoek. De aanvrager blijft ongewijzigd. Vereist daarnaast Verzoeken bekijken.'],
        'product-requests.resolve' => ['display_name' => 'Verzoeken afhandelen', 'category' => 'product_request_management', 'description' => 'Neem verzoeken in behandeling, los ze op, wijs ze af of heropen ze met een toelichting. Vereist daarnaast Verzoeken bekijken.'],
        'branding.manage' => ['display_name' => 'Branding en berichtteksten beheren', 'category' => 'branding_configuration', 'description' => 'Beheer huisstijl, logo, mail- en pushberichtteksten zonder toegang tot overige systeeminstellingen.'],
        'operational-map.view' => ['display_name' => 'Operationele kaart bekijken', 'category' => 'deployment_management', 'description' => 'Bekijk de operationele kaart met meldkamers en inzetlocaties.'],
        'operational-map.pilot-homes.view' => ['display_name' => 'Globale woonplaatsen op kaart bekijken', 'category' => 'deployment_management', 'description' => 'Toon globale woonplaatscoordinaten van piloten op de operationele kaart.'],
        'wallboards.manage' => ['display_name' => 'Wallboards beheren', 'category' => 'system_configuration', 'description' => 'Beheer wallboardindelingen, koppelcodes en afzonderlijke wallboardsessies.'],
        'system.health.view' => ['display_name' => 'Systeemstatus bekijken', 'category' => 'system_configuration', 'description' => 'Bekijk websocket-, versie- en servicestatus zonder beheersacties uit te voeren. Wachtrijen en routering hebben afzonderlijke bekijkrechten.'],
        'system.logs.view' => ['display_name' => 'Systeemlogbestanden bekijken', 'category' => 'system_configuration', 'description' => 'Bekijk begrensde en geredigeerde Laravel-applicatielogs in webbeheer.'],
        'system.queues.view' => ['display_name' => 'Wachtrijen bekijken', 'category' => 'system_configuration', 'description' => 'Bekijk wachtende, actieve en mislukte achtergrondtaken zonder ze te bedienen.'],
        'system.queues.manage' => ['display_name' => 'Wachtrijen bedienen', 'category' => 'system_configuration', 'description' => 'Start geschikte wachtende taken direct en herstart geschikte mislukte taken.'],
        'system.routing.view' => ['display_name' => 'Routeringsstatus bekijken', 'category' => 'system_configuration', 'description' => 'Bekijk de status en voortgang van lokale OSRM-routeringsdata zonder bewerkingen te starten.'],
        'system.routing.manage' => ['display_name' => 'OSRM-routering beheren', 'category' => 'system_configuration', 'description' => 'Installeer, activeer en werk de lokale OSRM-routeringsdata bij.'],
        'system.update.execute' => ['display_name' => 'Systeemupdate uitvoeren', 'category' => 'system_configuration', 'description' => 'Start een serverupdate. Dit is een afzonderlijke bevoorrechte actie.'],
        'system.reboot.execute' => ['display_name' => 'Server herstarten', 'category' => 'system_configuration', 'description' => 'Start een herstart van de DIS-server. Dit is een afzonderlijke bevoorrechte actie.'],
        'system.developer-access.manage' => ['display_name' => 'Developer-toegang beheren', 'category' => 'system_configuration', 'description' => 'Maak of trek developer API-sleutels in en bekijk de developerconfiguratie.'],
        'backups.manage' => ['display_name' => 'Backups beheren', 'category' => 'system_configuration', 'description' => 'Maak, verifieer, herstel en configureer automatische backups.'],
    ];

    /**
     * @var array<string, array{display_name: string, description: string, can_use_operator_app: bool, can_use_admin_app: bool, permissions: list<string>}>
     */
    private array $roles = [
        'system-administrator' => [
            'display_name' => 'System Administrator',
            'description' => 'Full platform administration and security-sensitive configuration.',
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
            'permissions' => ['*'],
        ],
        'national-coordinator' => [
            'display_name' => 'National Coordinator',
            'description' => 'Landelijke operationele coördinatie over inzetten, teams en alarmeringen.',
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'teams.manage', 'deployments.view', 'deployments.manage', 'deployment-requests.priority.override',
                'deployments.dispatch.view', 'deployments.dispatch.manage', 'status.view', 'status.override',
                'vacations.view',
                'assets.view', 'assets.manage', 'certifications.view', 'audit.view',
                'expiry.view',
                'address-book.view', 'settings.push.tokens.manage', 'settings.push.manual.send', 'system.health.view',
                'system.queues.view', 'system.routing.view',
                'operational-map.view', 'operational-map.pilot-homes.view',
                'calendar.view', 'calendar.register',
                'product-requests.view', 'product-requests.create', 'product-requests.update-own',
            ],
        ],
        'deployment-coordinator' => [
            'display_name' => 'Inzetcoördinator',
            'description' => 'Coördinatie en alarmeringsbeheer per inzet.',
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'deployments.view', 'deployments.manage', 'deployment-requests.priority.override', 'deployments.dispatch.view',
                'deployments.dispatch.manage', 'status.view', 'assets.view', 'certifications.view',
                'expiry.view', 'vacations.view',
                'address-book.view', 'settings.push.tokens.manage', 'settings.push.manual.send',
                'operational-map.view', 'operational-map.pilot-homes.view',
                'calendar.view', 'calendar.register',
                'product-requests.view', 'product-requests.create', 'product-requests.update-own',
            ],
        ],
        'operator-pilot' => [
            'display_name' => 'Operator / Pilot',
            'description' => 'Drone operator receiving dispatches and managing own operational status.',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
            'permissions' => [
                'deployments.assigned.view', 'calendar.view', 'calendar.register',
            ],
        ],
        'support-staff' => [
            'display_name' => 'Support Staff',
            'description' => 'Operational support for assets and certifications.',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'assets.view', 'assets.manage', 'certifications.view',
                'certifications.manage', 'expiry.view', 'status.view', 'vacations.view',
                'product-requests.view', 'product-requests.create', 'product-requests.update-own',
            ],
        ],
        'auditor' => [
            'display_name' => 'Auditor',
            'description' => 'Read-only inspection of operational and audit records.',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
            'permissions' => [
                'users.view', 'deployments.view', 'deployments.dispatch.view', 'status.view',
                'assets.view', 'certifications.view', 'expiry.view', 'vacations.view',
                'address-book.view', 'audit.view',
                'product-requests.view', 'product-requests.create', 'product-requests.update-own',
            ],
        ],
        'request-handler' => [
            'display_name' => 'Verzoekafhandelaar',
            'description' => 'Behandelt feature requests, wijzigingsverzoeken en bugmeldingen.',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
            'permissions' => [
                'product-requests.view',
                'product-requests.create',
                'product-requests.update-own',
                'product-requests.update-any',
                'product-requests.resolve',
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

            DB::table('permission_role')->where('role_id', $roleIds[$roleName])->delete();

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
