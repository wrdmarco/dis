<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, array{display_name: string, description: string}>
     */
    private array $permissions = [
        'incidents.view' => [
            'display_name' => 'Inzetten bekijken',
            'description' => 'Bekijk inzetten, details, tijdlijn en rapportstatus. Dit geeft geen recht om mensen te alarmeren of opkomst te bedienen.',
        ],
        'incidents.assigned.view' => [
            'display_name' => 'Eigen toegewezen inzetten bekijken',
            'description' => 'Bekijk in de operator-app uitsluitend inzetten waarvoor de gebruiker zelf ontvanger of geaccepteerde opkomer is.',
        ],
        'incidents.manage' => [
            'display_name' => 'Inzetregistratie beheren',
            'description' => 'Maak en wijzig inzetten, beheer status, kladblokregels, afsluiten en annuleren. Alarmeren en opkomst vallen onder inzetalarmering.',
        ],
        'incidents.delete' => [
            'display_name' => 'Inzetten verwijderen',
            'description' => 'Verwijder inzetten en gekoppelde operationele gegevens permanent. Gebruik alleen voor beheer.',
        ],
        'incidents.dispatch.view' => [
            'display_name' => 'Inzetalarmering bekijken',
            'description' => 'Bekijk vooraankondigingen, alarmeringen, gealarmeerde teams/personen, reacties, opkomststatus en dispatch-statistieken bij inzetten.',
        ],
        'incidents.dispatch.manage' => [
            'display_name' => 'Inzetalarmering bedienen',
            'description' => 'Bedien het alarmeringsproces rond een inzet: proefalarm, vooraankondigen, alarmeren, nadere info, opschalen, heralarmeren, annuleren, opkomst corrigeren en locatieverzoeken sturen.',
        ],
        'audit.view' => [
            'display_name' => 'Auditlog bekijken',
            'description' => 'Zoek en inspecteer auditlogs van beheer- en inzetacties.',
        ],
        'operational-map.view' => [
            'display_name' => 'Operationele kaart bekijken',
            'description' => 'Bekijk de operationele kaart met meldkamers en inzetlocaties.',
        ],
    ];

    /**
     * @var array<string, array{display_name?: string, description: string}>
     */
    private array $roles = [
        'national-coordinator' => [
            'description' => 'Landelijke operationele coördinatie over inzetten, teams en alarmeringen.',
        ],
        'incident-coordinator' => [
            'display_name' => 'Inzetcoördinator',
            'description' => 'Coördinatie en alarmeringsbeheer per inzet.',
        ],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->permissions as $name => $permission) {
            DB::table('permissions')
                ->where('name', $name)
                ->update([
                    ...$permission,
                    'updated_at' => $now,
                ]);
        }

        foreach ($this->roles as $name => $role) {
            DB::table('roles')
                ->where('name', $name)
                ->update([
                    ...$role,
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Descriptive labels intentionally keep the clarified terminology.
    }
};
