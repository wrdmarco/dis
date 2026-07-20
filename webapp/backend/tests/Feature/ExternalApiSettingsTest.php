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

    public function test_manager_can_store_knmi_and_aeret_keys_without_exposing_plaintext(): void
    {
        $manager = $this->manager();
        $knmiKey = 'knmi-edr-secret-value';
        $aeretKey = 'aeret-secret-value';

        $response = $this->asAdminClient($manager)
            ->patchJson('/api/admin/settings', [
                'settings' => [
                    'weather.knmi_edr_api_key' => $knmiKey,
                    'drone.aeret_api_key' => $aeretKey,
                ],
            ])
            ->assertOk();

        $settings = collect($response->json('data'))->keyBy('key');
        $this->assertSame(['configured' => true], $settings->get('weather.knmi_edr_api_key')['value'] ?? null);
        $this->assertSame(['configured' => true], $settings->get('drone.aeret_api_key')['value'] ?? null);
        $this->assertTrue((bool) ($settings->get('weather.knmi_edr_api_key')['is_sensitive'] ?? false));
        $this->assertTrue((bool) ($settings->get('drone.aeret_api_key')['is_sensitive'] ?? false));
        $this->assertStringNotContainsString($knmiKey, $response->getContent());
        $this->assertStringNotContainsString($aeretKey, $response->getContent());

        $knmiRaw = (string) DB::table('system_settings')
            ->where('key', 'weather.knmi_edr_api_key')
            ->value('value');
        $this->assertStringContainsString(SystemSettingValueCast::ENVELOPE_KEY, $knmiRaw);
        $this->assertStringNotContainsString($knmiKey, $knmiRaw);
        $this->assertSame($knmiKey, SystemSetting::string('weather.knmi_edr_api_key'));

        $listed = $this->asAdminClient($manager)
            ->getJson('/api/admin/settings')
            ->assertOk();
        $this->assertStringNotContainsString($knmiKey, $listed->getContent());
        $this->assertStringNotContainsString($aeretKey, $listed->getContent());
    }

    public function test_knmi_key_requires_a_bounded_string_and_blank_input_keeps_the_existing_secret(): void
    {
        $manager = $this->manager();
        SystemSetting::query()->create([
            'key' => 'weather.knmi_edr_api_key',
            'value' => 'existing-knmi-key',
            'is_sensitive' => true,
        ]);

        $client = $this->asAdminClient($manager);
        $client->patchJson('/api/admin/settings', [
            'settings' => ['weather.knmi_edr_api_key' => ['not-a-string']],
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['settings.weather.knmi_edr_api_key']]]);
        $client->patchJson('/api/admin/settings', [
            'settings' => ['weather.knmi_edr_api_key' => "invalid\nknmi-key"],
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['settings.weather.knmi_edr_api_key']]]);

        $blankResponse = $client->patchJson('/api/admin/settings', [
            'settings' => ['weather.knmi_edr_api_key' => ''],
        ]);
        $this->assertSame(200, $blankResponse->status(), $blankResponse->getContent());

        $this->assertSame('existing-knmi-key', SystemSetting::string('weather.knmi_edr_api_key'));
    }

    public function test_environment_only_knmi_key_is_reported_as_configured_without_being_exposed(): void
    {
        config(['dis.wallboards.uav_forecast.knmi_edr_api_key' => 'environment-knmi-secret']);

        $response = $this->asAdminClient($this->manager())
            ->getJson('/api/admin/settings')
            ->assertOk();
        $settings = collect($response->json('data'))->keyBy('key');

        $this->assertSame(['configured' => true], $settings->get('weather.knmi_edr_api_key')['value'] ?? null);
        $this->assertTrue((bool) ($settings->get('weather.knmi_edr_api_key')['is_sensitive'] ?? false));
        $this->assertDatabaseMissing('system_settings', ['key' => 'weather.knmi_edr_api_key']);
        $this->assertStringNotContainsString('environment-knmi-secret', $response->getContent());
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
            ['*', 'client:admin'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
