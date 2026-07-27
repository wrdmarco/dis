<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DeploymentReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class DeploymentReferenceAdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_manager_can_update_only_a_valid_reference_template_with_an_audit_log(): void
    {
        $manager = $this->manager();
        $template = 'NDT-{{date}}-{{time}}-{{sequence}}';

        $this->asAdminClient($manager)
            ->patchJson('/api/admin/settings', [
                'settings' => [
                    DeploymentReferenceService::SETTING_KEY => $template,
                ],
            ])
            ->assertOk()
            ->assertJsonFragment([
                'key' => DeploymentReferenceService::SETTING_KEY,
                'value' => $template,
            ]);

        $this->assertSame($template, SystemSetting::string(DeploymentReferenceService::SETTING_KEY));
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $manager->id,
            'action' => 'admin.settings_updated',
        ]);

        foreach ([
            'NDT-{{random}}',
            'NDT/{{sequence}}',
            'NDT-{{unknown}}-{{sequence}}',
        ] as $invalidTemplate) {
            $this->asAdminClient($manager)
                ->patchJson('/api/admin/settings', [
                    'settings' => [
                        DeploymentReferenceService::SETTING_KEY => $invalidTemplate,
                    ],
                ])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed');

            $this->assertSame($template, SystemSetting::string(DeploymentReferenceService::SETTING_KEY));
        }
    }

    private function manager(): User
    {
        $user = User::query()->create([
            'name' => 'Inzetreferentiebeheerder',
            'first_name' => 'Inzetreferentie',
            'last_name' => 'Beheerder',
            'email' => 'deployment-reference-settings@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'settings.manage'],
            [
                'display_name' => 'Systeeminstellingen beheren',
                'category' => 'system_configuration',
                'description' => 'Beheer systeeminstellingen.',
            ],
        );
        $role = Role::query()->create([
            'name' => 'deployment-reference-settings-manager',
            'display_name' => 'Inzetreferentiebeheerder',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        $role->permissions()->attach($permission->id, ['created_at' => now()]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken(
            'Deployment reference settings test',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
