<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var array<string, array{new_name: string, display_name: string, category: string, description: string, old_display_name: string, old_category: string, old_description: string}>
     */
    private array $permissionRenames = [
        'dispatch.view' => [
            'new_name' => 'incidents.dispatch.view',
            'display_name' => 'Incidentalarmering bekijken',
            'category' => 'incident_management',
            'description' => 'Bekijk vooraankondigingen, alarmeringen, gealarmeerde teams/personen, reacties, opkomststatus en dispatch-statistieken bij incidenten.',
            'old_display_name' => 'Alarmeringen bekijken',
            'old_category' => 'dispatch_management',
            'old_description' => 'Bekijk alarmeringen, ontvangers, reacties en opkomststatus.',
        ],
        'dispatch.manage' => [
            'new_name' => 'incidents.dispatch.manage',
            'display_name' => 'Incidentalarmering bedienen',
            'category' => 'incident_management',
            'description' => 'Bedien het alarmeringsproces rond een incident: proefalarm, vooraankondigen, alarmeren, nadere info, opschalen, heralarmeren, annuleren, opkomst corrigeren en locatieverzoeken sturen.',
            'old_display_name' => 'Alarmeringen beheren',
            'old_category' => 'dispatch_management',
            'old_description' => 'Maak, verstuur, annuleer, schaal op en heralarmeer dispatches.',
        ],
    ];

    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->permissionRenames as $oldName => $permission) {
            $this->movePermission(
                $oldName,
                $permission['new_name'],
                [
                    'display_name' => $permission['display_name'],
                    'category' => $permission['category'],
                    'description' => $permission['description'],
                ],
                $now,
            );
        }
    }

    public function down(): void
    {
        $now = Carbon::now();

        foreach ($this->permissionRenames as $oldName => $permission) {
            $this->movePermission(
                $permission['new_name'],
                $oldName,
                [
                    'display_name' => $permission['old_display_name'],
                    'category' => $permission['old_category'],
                    'description' => $permission['old_description'],
                ],
                $now,
            );
        }
    }

    /**
     * @param array{display_name: string, category: string, description: string} $metadata
     */
    private function movePermission(string $fromName, string $toName, array $metadata, Carbon $now): void
    {
        $fromId = DB::table('permissions')->where('name', $fromName)->value('id');
        $toId = DB::table('permissions')->where('name', $toName)->value('id');

        if (is_string($fromId) && is_string($toId) && $fromId !== $toId) {
            $roleIds = DB::table('permission_role')->where('permission_id', $fromId)->pluck('role_id')->all();
            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'permission_id' => $toId,
                        'role_id' => $roleId,
                    ],
                    ['created_at' => $now],
                );
            }

            DB::table('permission_role')->where('permission_id', $fromId)->delete();
            DB::table('permissions')->where('id', $fromId)->delete();
            DB::table('permissions')->where('id', $toId)->update([
                ...$metadata,
                'updated_at' => $now,
            ]);

            return;
        }

        if (is_string($fromId)) {
            DB::table('permissions')->where('id', $fromId)->update([
                'name' => $toName,
                ...$metadata,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => $toName],
            [
                'id' => is_string($toId) ? $toId : (string) Str::ulid(),
                ...$metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
};
