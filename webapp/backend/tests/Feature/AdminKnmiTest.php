<?php

namespace Tests\Feature;

use App\Casts\SystemSettingValueCast;
use App\Jobs\RefreshKnmiForecastDataset;
use App\Jobs\RefreshKnmiPrecipitationOutlookSnapshot;
use App\Models\KnmiForecastOperation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\KnmiForecastOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdminKnmiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'dis.knmi_forecast.api_key' => null,
            'dis.wallboards.uav_forecast.knmi_edr_api_key' => null,
        ]);
    }

    public function test_knmi_page_requires_settings_permission_and_never_exposes_keys(): void
    {
        $this->getJson('/api/admin/knmi')->assertUnauthorized();
        $viewer = $this->user('knmi-viewer@example.test', []);
        $this->asAdminClient($viewer)->getJson('/api/admin/knmi')->assertForbidden();

        SystemSetting::query()->create([
            'key' => 'weather.knmi_edr_api_key',
            'value' => 'legacy-edr-key-123456',
            'is_sensitive' => true,
        ]);
        $manager = $this->user('knmi-manager@example.test', ['settings.manage']);
        $response = $this->asAdminClient($manager)
            ->getJson('/api/admin/knmi')
            ->assertOk()
            ->assertJsonPath('data.configuration.configured', true)
            ->assertJsonPath('data.configuration.open_data_api_key_configured', false)
            ->assertJsonPath('data.configuration.open_data_api_key_source', null)
            ->assertJsonPath('data.configuration.edr_api_key_configured', true)
            ->assertJsonPath('data.configuration.edr_api_key_source', 'edr_setting')
            ->assertJsonPath('data.configuration.dataset', 'harmonie_arome_cy43_p1')
            ->assertJsonPath('data.configuration.dataset_version', '1.0')
            ->assertJsonPath('data.configuration.automatic_interval_hours', 3)
            ->assertJsonPath('data.active_snapshot', null)
            ->assertJsonPath('data.active_operation', null);

        $this->assertStringNotContainsString('legacy-edr-key-123456', $response->getContent());
    }

    public function test_manager_can_update_both_encrypted_keys_on_the_dedicated_page(): void
    {
        $manager = $this->user('knmi-settings@example.test', ['settings.manage']);
        $openDataKey = 'open-data-public-key-123456';
        $edrKey = 'edr-observation-key-123456';

        $response = $this->asAdminClient($manager)
            ->patchJson('/api/admin/knmi', [
                'open_data_api_key' => $openDataKey,
                'edr_api_key' => $edrKey,
            ])
            ->assertOk()
            ->assertJsonPath('data.configuration.open_data_api_key_configured', true)
            ->assertJsonPath('data.configuration.open_data_api_key_source', 'open_data_setting')
            ->assertJsonPath('data.configuration.edr_api_key_configured', true)
            ->assertJsonPath('data.configuration.edr_api_key_source', 'edr_setting');
        $this->assertStringNotContainsString($openDataKey, $response->getContent());
        $this->assertStringNotContainsString($edrKey, $response->getContent());
        $this->assertSame($openDataKey, SystemSetting::string('weather.knmi_open_data_api_key'));
        $this->assertSame($edrKey, SystemSetting::string('weather.knmi_edr_api_key'));

        foreach (['weather.knmi_open_data_api_key', 'weather.knmi_edr_api_key'] as $key) {
            $raw = (string) DB::table('system_settings')->where('key', $key)->value('value');
            $this->assertStringContainsString(SystemSettingValueCast::ENVELOPE_KEY, $raw);
            $this->assertStringNotContainsString($key === 'weather.knmi_open_data_api_key' ? $openDataKey : $edrKey, $raw);
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'weather.knmi.settings_updated']);

        $generic = $this->asAdminClient($manager)->getJson('/api/admin/settings')->assertOk();
        $this->assertNotContains('weather.knmi_open_data_api_key', collect($generic->json('data'))->pluck('key')->all());
        $this->assertNotContains('weather.knmi_edr_api_key', collect($generic->json('data'))->pluck('key')->all());
        $this->asAdminClient($manager)->patchJson('/api/admin/settings', [
            'settings' => ['weather.knmi_edr_api_key' => 'bypass-key-123456789'],
        ])->assertUnprocessable();
    }

    public function test_key_update_requires_at_least_one_valid_nonempty_key(): void
    {
        $manager = $this->user('knmi-invalid@example.test', ['settings.manage']);

        $this->asAdminClient($manager)->patchJson('/api/admin/knmi', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['settings']]]);
        $this->asAdminClient($manager)->patchJson('/api/admin/knmi', [
            'open_data_api_key' => "invalid\nkey-value-that-is-long",
        ])->assertUnprocessable();
        $this->asAdminClient($manager)->patchJson('/api/admin/knmi', [
            'edr_api_key' => '',
        ])->assertUnprocessable();
    }

    public function test_refresh_is_queued_once_on_the_dedicated_queue_and_audited(): void
    {
        Queue::fake();
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'open-data-public-key-123456',
            'is_sensitive' => true,
        ]);
        $manager = $this->user('knmi-refresh@example.test', ['settings.manage']);

        $response = $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/refresh')
            ->assertStatus(202)
            ->assertJsonPath('data.operation.state', 'queued')
            ->assertJsonPath('data.operation.stage', 'queued');
        $operationId = $response->json('data.operation.id');
        Queue::assertPushed(RefreshKnmiForecastDataset::class, function (RefreshKnmiForecastDataset $job) use ($operationId): bool {
            return $job->operationId === $operationId
                && $job->connection === 'knmi'
                && $job->queue === 'knmi';
        });
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'weather.knmi.refresh_requested',
            'target_id' => $operationId,
        ]);

        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/refresh')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'knmi_refresh_conflict');
        $this->assertDatabaseCount('knmi_forecast_operations', 1);
        $this->asAdminClient($manager)
            ->getJson('/api/admin/knmi')
            ->assertOk()
            ->assertJsonPath('data.active_operation.id', $operationId)
            ->assertJsonPath('data.active_operation.downloaded_bytes', 0);
    }

    public function test_manager_can_request_a_local_precipitation_refresh_from_the_knmi_page(): void
    {
        Queue::fake();
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'open-data-public-key-123456',
            'is_sensitive' => true,
        ]);
        $manager = $this->user('knmi-precipitation@example.test', ['settings.manage']);

        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/precipitation/refresh')
            ->assertStatus(202)
            ->assertJsonPath('data.requested', true);

        Queue::assertPushed(RefreshKnmiPrecipitationOutlookSnapshot::class, function (RefreshKnmiPrecipitationOutlookSnapshot $job): bool {
            return $job->connection === 'knmi_realtime'
                && $job->queue === 'knmi-realtime';
        });
        Queue::assertNotPushed(RefreshKnmiForecastDataset::class);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'weather.knmi.precipitation_refresh_requested',
            'target_type' => RefreshKnmiPrecipitationOutlookSnapshot::class,
        ]);
    }

    public function test_precipitation_refresh_requires_configuration_and_permission(): void
    {
        Queue::fake();
        $viewer = $this->user('knmi-precipitation-viewer@example.test', []);
        $manager = $this->user('knmi-precipitation-unconfigured@example.test', ['settings.manage']);

        $this->postJson('/api/admin/knmi/precipitation/refresh')->assertUnauthorized();
        $this->asAdminClient($viewer)
            ->postJson('/api/admin/knmi/precipitation/refresh')
            ->assertForbidden();
        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/precipitation/refresh')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'knmi_precipitation_refresh_conflict');

        Queue::assertNotPushed(RefreshKnmiPrecipitationOutlookSnapshot::class);
    }

    public function test_knmi_queue_visibility_timeout_exceeds_the_job_timeout(): void
    {
        $job = new RefreshKnmiForecastDataset('01arz3ndektsv4rrffq69g5fav');

        $this->assertSame('knmi', $job->connection);
        $this->assertSame('knmi', $job->queue);
        $this->assertSame(7200, $job->timeout);
        $this->assertSame(10800, $job->uniqueFor);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.knmi.retry_after'));
        $this->assertTrue((bool) config('queue.connections.knmi.after_commit'));
    }

    public function test_failed_worker_releases_the_unique_operation_slot(): void
    {
        $operation = KnmiForecastOperation::query()->create([
            'state' => KnmiForecastOperation::STATE_RUNNING,
            'stage' => 'downloading',
            'active_key' => KnmiForecastOperation::ACTIVE_KEY,
            'message' => 'Running.',
            'progress_percent' => 20,
            'downloaded_bytes' => 100,
            'total_bytes' => 500,
            'started_at' => now(),
        ]);
        $storageRoot = storage_path('framework/testing/knmi-failed-'.$operation->id);
        config()->set('dis.knmi_forecast.storage_root', $storageRoot);
        File::makeDirectory($storageRoot.'/staging/'.$operation->id.'/release', 0770, true);
        file_put_contents($storageRoot.'/staging/'.$operation->id.'/partial.tar', 'partial');

        (new RefreshKnmiForecastDataset($operation->id))->failed(new \RuntimeException('signed-url-must-not-be-persisted'));

        $operation->refresh();
        $this->assertSame(KnmiForecastOperation::STATE_FAILED, $operation->state);
        $this->assertNull($operation->active_key);
        $this->assertSame('worker_failed', $operation->error_code);
        $this->assertStringNotContainsString('signed-url', $operation->message);
        $this->assertDirectoryDoesNotExist($storageRoot.'/staging/'.$operation->id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'weather.knmi.refresh_failed',
            'target_id' => $operation->id,
        ]);
        File::deleteDirectory($storageRoot);
    }

    public function test_scheduled_refresh_transactionally_recovers_a_stale_queued_operation(): void
    {
        Queue::fake();
        config()->set('dis.knmi_forecast.api_key', 'knmi-open-data-key-123456');
        $stale = KnmiForecastOperation::query()->create([
            'state' => KnmiForecastOperation::STATE_QUEUED,
            'stage' => 'queued',
            'active_key' => KnmiForecastOperation::ACTIVE_KEY,
            'message' => 'Stale.',
            'progress_percent' => 0,
            'downloaded_bytes' => 0,
        ]);
        $stale->forceFill([
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ])->save();

        $replacement = app(KnmiForecastOperationService::class)
            ->requestRefresh(scheduled: true);

        $this->assertSame(KnmiForecastOperation::STATE_FAILED, $stale->refresh()->state);
        $this->assertSame('operation_stale', $stale->error_code);
        $this->assertNull($stale->active_key);
        $this->assertSame(KnmiForecastOperation::STATE_QUEUED, $replacement->state);
        $this->assertSame(KnmiForecastOperation::ACTIVE_KEY, $replacement->active_key);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'weather.knmi.refresh_stale',
            'target_id' => $stale->id,
        ]);
    }

    private function user(string $email, array $permissionNames): User
    {
        $user = User::query()->create([
            'name' => 'KNMI Manager',
            'first_name' => 'KNMI',
            'last_name' => 'Manager',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'knmi-'.str()->lower((string) str()->ulid()),
            'display_name' => 'KNMI test role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        foreach ($permissionNames as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'category' => 'system_configuration', 'description' => $name],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('KNMI test', ['*', 'client:admin'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
