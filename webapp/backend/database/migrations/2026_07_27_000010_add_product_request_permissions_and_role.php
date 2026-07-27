<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * @var array<string, array{display_name: string, description: string}>
     */
    private array $permissions = [
        'product-requests.view' => [
            'display_name' => 'Verzoeken bekijken',
            'description' => 'Bekijk alle ingediende feature requests, wijzigingsverzoeken en bugmeldingen.',
        ],
        'product-requests.create' => [
            'display_name' => 'Verzoeken indienen',
            'description' => 'Dien een feature request, wijzigingsverzoek of bugmelding in. Vereist daarnaast Verzoeken bekijken.',
        ],
        'product-requests.update-own' => [
            'display_name' => 'Eigen verzoeken aanpassen',
            'description' => 'Wijzig de inhoud van eigen verzoeken zolang deze niet zijn opgelost of afgewezen. Vereist daarnaast Verzoeken bekijken.',
        ],
        'product-requests.resolve' => [
            'display_name' => 'Verzoeken afhandelen',
            'description' => 'Neem verzoeken in behandeling, los ze op, wijs ze af of heropen ze met een toelichting. Vereist daarnaast Verzoeken bekijken.',
        ],
    ];

    public function up(): void
    {
        $now = now();
        $permissionIds = [];

        foreach ($this->permissions as $name => $definition) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            if (! is_string($permissionId)) {
                $permissionId = (string) Str::ulid();
            }

            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                [
                    'id' => $permissionId,
                    'category' => 'product_request_management',
                    'display_name' => $definition['display_name'],
                    'description' => $definition['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $permissionIds[$name] = $permissionId;
        }

        $handlerRoleId = DB::table('roles')->where('name', 'request-handler')->value('id');
        if (! is_string($handlerRoleId)) {
            $handlerRoleId = (string) Str::ulid();
        }

        DB::table('roles')->updateOrInsert(
            ['name' => 'request-handler'],
            [
                'id' => $handlerRoleId,
                'display_name' => 'Verzoekafhandelaar',
                'description' => 'Behandelt feature requests, wijzigingsverzoeken en bugmeldingen.',
                'requires_two_factor' => false,
                'can_use_operator_app' => false,
                'can_use_admin_app' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $standardPermissionNames = [
            'product-requests.view',
            'product-requests.create',
            'product-requests.update-own',
        ];
        $webRoleIds = DB::table('roles')
            ->where('can_use_admin_app', true)
            ->pluck('id');

        foreach ($webRoleIds as $roleId) {
            foreach ($standardPermissionNames as $permissionName) {
                $this->grant((string) $roleId, $permissionIds[$permissionName], $now);
            }
        }

        $administratorRoleId = DB::table('roles')
            ->where('name', 'system-administrator')
            ->value('id');
        if (is_string($administratorRoleId)) {
            $this->grant(
                $administratorRoleId,
                $permissionIds['product-requests.resolve'],
                $now,
            );
        }

        foreach ($permissionIds as $permissionId) {
            $this->grant($handlerRoleId, $permissionId, $now);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys($this->permissions))
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        DB::table('roles')->where('name', 'request-handler')->delete();
    }

    private function grant(string $roleId, string $permissionId, DateTimeInterface $now): void
    {
        DB::table('permission_role')->updateOrInsert(
            [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ],
            ['created_at' => $now],
        );
    }
};
