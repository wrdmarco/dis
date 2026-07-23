<?php

namespace Tests\Feature;

use App\Casts\SystemSettingValueCast;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ExternalApiSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_store_aeret_key_without_exposing_plaintext(): void
    {
        $manager = $this->manager();
        $aeretKey = 'aeret-secret-value';

        $response = $this->asAdminClient($manager)
            ->patchJson('/api/admin/settings', [
                'settings' => [
                    'drone.aeret_api_key' => $aeretKey,
                ],
            ])
            ->assertOk();

        $settings = collect($response->json('data'))->keyBy('key');
        $this->assertSame(['configured' => true], $settings->get('drone.aeret_api_key')['value'] ?? null);
        $this->assertTrue((bool) ($settings->get('drone.aeret_api_key')['is_sensitive'] ?? false));
        $this->assertStringNotContainsString($aeretKey, $response->getContent());

        $aeretRaw = (string) DB::table('system_settings')
            ->where('key', 'drone.aeret_api_key')
            ->value('value');
        $this->assertStringContainsString(SystemSettingValueCast::ENVELOPE_KEY, $aeretRaw);
        $this->assertStringNotContainsString($aeretKey, $aeretRaw);
        $this->assertSame($aeretKey, SystemSetting::string('drone.aeret_api_key'));

        $listed = $this->asAdminClient($manager)
            ->getJson('/api/admin/settings')
            ->assertOk();
        $this->assertStringNotContainsString($aeretKey, $listed->getContent());
    }

    private function manager(): User
    {
        $user = User::query()->create([
            'name' => 'External API Settings Manager',
            'first_name' => 'External API',
            'last_name' => 'Settings Manager',
            'email' => 'external-api-settings@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'settings.manage'],
            [
                'display_name' => 'Manage settings',
                'category' => 'system_configuration',
                'description' => 'Manage system settings.',
            ],
        );
        $role = Role::query()->create([
            'name' => 'external-api-settings-manager',
            'display_name' => 'External API settings manager',
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
            'External API settings test',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
