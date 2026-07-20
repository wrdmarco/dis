<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\PilotIncidentReportFormService;
use App\Services\PilotIncidentReportService;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PilotReportDroneFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_and_seeded_forms_offer_the_optional_user_drone_select(): void
    {
        $expected = $this->droneField();
        $defaults = app(PilotIncidentReportFormService::class)->defaultFields();
        self::assertSame($expected, collect($defaults)->firstWhere('key', 'drone_used'));

        $this->seed(SystemSettingSeeder::class);
        $stored = SystemSetting::value(PilotIncidentReportFormService::SETTING_KEY);
        self::assertIsArray($stored);
        self::assertEquals($expected, collect($stored)->firstWhere('key', 'drone_used'));
    }

    public function test_data_migration_appends_once_without_replacing_admin_configuration(): void
    {
        $customField = [
            'key' => 'customer_reference',
            'label' => 'Eigen veld',
            'type' => 'text',
            'visible' => false,
            'required' => false,
        ];
        SystemSetting::query()->create([
            'key' => PilotIncidentReportFormService::SETTING_KEY,
            'value' => [$customField],
            'is_sensitive' => false,
        ]);
        $migration = require database_path('migrations/2026_07_20_000004_add_drone_used_to_pilot_report_form.php');

        $migration->up();
        $migration->up();

        $stored = SystemSetting::value(PilotIncidentReportFormService::SETTING_KEY);
        self::assertIsArray($stored);
        self::assertEquals($customField, $stored[0]);
        self::assertCount(1, array_filter(
            $stored,
            static fn (mixed $field): bool => is_array($field) && ($field['key'] ?? null) === 'drone_used',
        ));
        self::assertEquals($this->droneField(), collect($stored)->firstWhere('key', 'drone_used'));

        SystemSetting::query()
            ->findOrFail(PilotIncidentReportFormService::SETTING_KEY)
            ->forceFill(['value' => [$stored[0], array_reverse($this->droneField(), true)]])
            ->save();
        $migration->down();
        self::assertEquals([$customField], SystemSetting::value(PilotIncidentReportFormService::SETTING_KEY));
    }

    public function test_data_migration_preserves_an_existing_admin_drone_field(): void
    {
        $adminField = [
            'key' => 'drone_used',
            'label' => 'Toestel tijdens inzet',
            'type' => 'select',
            'visible' => false,
            'required' => true,
            'option_source' => 'user_drones',
        ];
        SystemSetting::query()->create([
            'key' => PilotIncidentReportFormService::SETTING_KEY,
            'value' => [$adminField],
            'is_sensitive' => false,
        ]);
        $migration = require database_path('migrations/2026_07_20_000004_add_drone_used_to_pilot_report_form.php');

        $migration->up();

        self::assertEquals([$adminField], SystemSetting::value(PilotIncidentReportFormService::SETTING_KEY));
    }

    public function test_data_migration_rollback_preserves_post_migration_admin_edits(): void
    {
        SystemSetting::query()->create([
            'key' => PilotIncidentReportFormService::SETTING_KEY,
            'value' => [],
            'is_sensitive' => false,
        ]);
        $migration = require database_path('migrations/2026_07_20_000004_add_drone_used_to_pilot_report_form.php');
        $migration->up();

        $edited = $this->droneField();
        $edited['label'] = 'Operationeel gebruikt toestel';
        $edited['required'] = true;
        SystemSetting::query()
            ->findOrFail(PilotIncidentReportFormService::SETTING_KEY)
            ->forceFill(['value' => [$edited]])
            ->save();

        $migration->down();

        self::assertEquals([$edited], SystemSetting::value(PilotIncidentReportFormService::SETTING_KEY));
        self::assertDatabaseMissing('system_settings', [
            'key' => 'migration.2026_07_20_000004.drone_used_added',
        ]);
    }

    public function test_legacy_integer_flight_minutes_remain_compatible_with_standard_report_storage(): void
    {
        $method = new \ReflectionMethod(PilotIncidentReportService::class, 'standardValuesFromCustomFields');
        $service = app(PilotIncidentReportService::class);

        self::assertSame(37, $method->invoke($service, ['flight_minutes' => 37])['flight_minutes']);
        self::assertSame(25, $method->invoke($service, [
            'flight_time' => ['duration_minutes' => 25],
            'flight_minutes' => 37,
        ])['flight_minutes']);
        self::assertNull($method->invoke($service, ['flight_minutes' => '37'])['flight_minutes']);
        self::assertNull($method->invoke($service, ['flight_minutes' => 1441])['flight_minutes']);
    }

    public function test_non_drone_reserved_key_collision_is_preserved_but_never_interpreted_as_an_asset(): void
    {
        $collision = [
            'key' => 'drone_used',
            'label' => 'Vrij tekstveld met bestaande betekenis',
            'type' => 'text',
            'visible' => true,
            'required' => false,
        ];
        SystemSetting::query()->create([
            'key' => PilotIncidentReportFormService::SETTING_KEY,
            'value' => [$collision],
            'is_sensitive' => false,
        ]);
        $migration = require database_path('migrations/2026_07_20_000004_add_drone_used_to_pilot_report_form.php');

        $migration->up();

        self::assertEquals([$collision], SystemSetting::value(PilotIncidentReportFormService::SETTING_KEY));
        self::assertSame([], app(PilotIncidentReportFormService::class)->droneFieldKeys());
    }

    /** @return array<string, mixed> */
    private function droneField(): array
    {
        return [
            'key' => 'drone_used',
            'label' => 'Gebruikte drone',
            'type' => 'select',
            'visible' => true,
            'required' => false,
            'max_length' => 1000,
            'max' => 1440,
            'option_source' => 'user_drones',
            'options' => [],
            'is_custom' => true,
        ];
    }
}
