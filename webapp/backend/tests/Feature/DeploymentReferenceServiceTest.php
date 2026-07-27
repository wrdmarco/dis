<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DeploymentReferenceService;
use App\Services\DeploymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DeploymentReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_validates_supported_safe_and_unique_templates(): void
    {
        $service = app(DeploymentReferenceService::class);

        $this->assertSame(
            'NDT-{{date}}-{{time}}-{{sequence}}',
            $service->validateTemplate('  NDT-{{date}}-{{time}}-{{sequence}}  '),
        );
        $this->assertSame(
            DeploymentReferenceService::DEFAULT_TEMPLATE,
            $service->validateTemplate(DeploymentReferenceService::DEFAULT_TEMPLATE),
        );

        foreach ([
            'NDT-{{date}}',
            'NDT-{{random}}',
            'NDT-{{unknown}}-{{sequence}}',
            'NDT/{{sequence}}',
            'NDT {{sequence}}',
            "NDT-\r\n{{sequence}}",
        ] as $invalidTemplate) {
            try {
                $service->validateTemplate($invalidTemplate);
                $this->fail("Sjabloon had ongeldig moeten zijn: {$invalidTemplate}");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(
                    'settings.'.DeploymentReferenceService::SETTING_KEY,
                    $exception->errors(),
                );
            }
        }
    }

    public function test_default_template_preserves_the_existing_reference_semantics(): void
    {
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:11:12', 'Europe/Amsterdam'));

        $deployment = app(DeploymentService::class)->create(
            $this->deploymentData(),
            $this->user('default-reference@example.test'),
        );

        $this->assertMatchesRegularExpression(
            '/^DIS-20260727-101112-[A-F0-9]{4}$/',
            $deployment->reference,
        );
        $this->assertSame(1, $deployment->reference_sequence);
    }

    public function test_configured_reference_and_sequence_are_server_managed_immutable_snapshots(): void
    {
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:11:12', 'Europe/Amsterdam'));
        $actor = $this->user('configured-reference@example.test');
        $deployments = app(DeploymentService::class);

        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentReferenceService::SETTING_KEY],
            [
                'value' => 'NDT-{{date}}-{{time}}-{{sequence}}',
                'is_sensitive' => false,
            ],
        );

        $first = $deployments->create($this->deploymentData([
            'reference' => 'CLIENT-REFERENCE',
            'reference_sequence' => 9876,
        ]), $actor);
        $this->assertSame('NDT-20260727-101112-0001', $first->reference);
        $this->assertSame(1, $first->reference_sequence);

        SystemSetting::query()
            ->whereKey(DeploymentReferenceService::SETTING_KEY)
            ->update(['value' => json_encode('NIEUW-{{sequence}}', JSON_THROW_ON_ERROR)]);

        $second = $deployments->create($this->deploymentData(), $actor);
        $this->assertSame('NIEUW-0002', $second->reference);
        $this->assertSame(2, $second->reference_sequence);

        $updated = $deployments->update($first->refresh(), [
            'title' => 'Titel mag wijzigen',
            'reference' => 'OVERSCHREVEN',
            'reference_sequence' => 9999,
        ], $actor);
        $this->assertSame('Titel mag wijzigen', $updated->title);
        $this->assertSame('NDT-20260727-101112-0001', $updated->reference);
        $this->assertSame(1, $updated->reference_sequence);
    }

    public function test_template_switch_skips_a_reference_that_already_exists_without_reusing_the_sequence(): void
    {
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:11:12', 'Europe/Amsterdam'));
        $actor = $this->user('reference-collision@example.test');
        $deployments = app(DeploymentService::class);

        Deployment::query()->create([
            'reference' => 'NIEUW-0002',
            'title' => 'Bestaande inzet',
            'description' => 'Referentie die al bestond voordat het sjabloon werd gewijzigd.',
            'priority' => 'normal',
            'status' => 'resolved',
            'is_test' => false,
            'created_by' => $actor->id,
            'created_by_name' => $actor->name,
            'created_by_email' => $actor->email,
            'opened_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
        ]);

        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentReferenceService::SETTING_KEY],
            [
                'value' => 'OUD-{{sequence}}',
                'is_sensitive' => false,
            ],
        );
        $first = $deployments->create($this->deploymentData(), $actor);
        $this->assertSame('OUD-0001', $first->reference);
        $this->assertSame(1, $first->reference_sequence);

        SystemSetting::query()
            ->whereKey(DeploymentReferenceService::SETTING_KEY)
            ->update(['value' => json_encode('NIEUW-{{sequence}}', JSON_THROW_ON_ERROR)]);

        $afterSwitch = $deployments->create($this->deploymentData(), $actor);

        $this->assertSame('NIEUW-0003', $afterSwitch->reference);
        $this->assertSame(3, $afterSwitch->reference_sequence);
        $this->assertSame(
            3,
            (int) DB::table('deployment_reference_sequence_counters')
                ->where('scope', 'global')
                ->value('last_sequence'),
        );
    }

    public function test_test_deployments_keep_test_references_without_reserving_a_sequence(): void
    {
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:11:12', 'Europe/Amsterdam'));

        $deployment = app(DeploymentService::class)->create(
            $this->deploymentData(['is_test' => true]),
            $this->user('test-reference@example.test'),
        );

        $this->assertMatchesRegularExpression(
            '/^TEST-20260727-101112-[A-F0-9]{4}$/',
            $deployment->reference,
        );
        $this->assertNull($deployment->reference_sequence);
        $this->assertSame(
            0,
            (int) DB::table('deployment_reference_sequence_counters')
                ->where('scope', 'global')
                ->value('last_sequence'),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function deploymentData(array $overrides = []): array
    {
        return $overrides + [
            'title' => 'Testinzet',
            'description' => 'Test van configureerbare inzetreferenties.',
            'priority' => 'normal',
            'custom_fields' => [],
        ];
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Referentie Testgebruiker',
            'first_name' => 'Referentie',
            'last_name' => 'Testgebruiker',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
