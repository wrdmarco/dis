<?php

namespace Tests\Feature;

use App\Casts\SystemSettingValueCast;
use App\Jobs\RefreshKnmiForecastDataset;
use App\Jobs\RefreshWeatherDatasetOperation;
use App\Models\KnmiForecastOperation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WeatherDatasetOperation;
use App\Services\KnmiForecastOperationService;
use App\Services\WeatherDatasetOperationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
            ->assertJsonPath('data.configuration.configured', false)
            ->assertJsonPath('data.configuration.open_data_api_key_configured', false)
            ->assertJsonPath('data.configuration.open_data_api_key_source', null)
            ->assertJsonPath('data.configuration.edr_api_key_configured', true)
            ->assertJsonPath('data.configuration.edr_api_key_source', 'edr_setting')
            ->assertJsonPath('data.configuration.dataset', 'harmonie_arome_cy43_p1')
            ->assertJsonPath('data.configuration.dataset_version', '1.0')
            ->assertJsonPath('data.configuration.automatic_interval_hours', 3)
            ->assertJsonPath('data.active_snapshot', null)
            ->assertJsonPath('data.active_operation', null)
            ->assertJsonPath('data.datasets.0.key', 'harmonie_arome_cy43_p1')
            ->assertJsonPath('data.datasets.0.status', 'not_configured')
            ->assertJsonPath('data.datasets.1.key', 'radar_forecast')
            ->assertJsonPath('data.datasets.3.key', 'knmi_edr_observations')
            ->assertJsonPath('data.datasets.3.status', 'on_demand')
            ->assertJsonCount(7, 'data.datasets');

        $this->assertStringNotContainsString('legacy-edr-key-123456', $response->getContent());
        $datasets = collect($response->json('data.datasets'));
        $this->assertSame(7, $datasets->whereIn('category', ['active', 'on_demand'])->count());
        foreach ($datasets as $dataset) {
            $this->assertSame([
                'key',
                'provider',
                'dataset',
                'version',
                'consumers',
                'category',
                'storage_mode',
                'status',
                'configured',
                'reference_at',
                'refreshed_at',
                'next_update_at',
                'source_url',
                'availability_note',
                'latest_error',
                'refreshable',
                'operation',
            ], array_keys($dataset));
        }
        $this->assertNull($datasets->firstWhere('key', 'open_meteo')['next_update_at']);
        $this->assertNotNull($datasets->firstWhere('key', 'radar_forecast')['next_update_at']);
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

    public function test_dataset_inventory_reports_honest_next_scheduler_times(): void
    {
        CarbonImmutable::setTestNow('2026-07-23T14:01:30Z');
        try {
            $manager = $this->user('knmi-schedule@example.test', ['settings.manage']);
            $datasets = collect(
                $this->asAdminClient($manager)
                    ->getJson('/api/admin/knmi')
                    ->assertOk()
                    ->json('data.datasets'),
            );

            $this->assertSame(
                '2026-07-23T16:17:00+00:00',
                $datasets->firstWhere('key', 'harmonie_arome_cy43_p1')['next_update_at'],
            );
            $this->assertSame(
                '2026-07-23T14:04:00+00:00',
                $datasets->firstWhere('key', 'radar_forecast')['next_update_at'],
            );
            $this->assertSame(
                '2026-07-23T14:05:00+00:00',
                $datasets->firstWhere('key', 'eumetsat_mtg_li')['next_update_at'],
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_harmonie_next_update_uses_scheduler_timezone_in_winter(): void
    {
        CarbonImmutable::setTestNow('2026-01-23T14:01:30Z');
        try {
            $manager = $this->user('knmi-winter-schedule@example.test', ['settings.manage']);
            $datasets = collect(
                $this->asAdminClient($manager)
                    ->getJson('/api/admin/knmi')
                    ->assertOk()
                    ->json('data.datasets'),
            );

            $this->assertSame(
                '2026-01-23T14:17:00+00:00',
                $datasets->firstWhere('key', 'harmonie_arome_cy43_p1')['next_update_at'],
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
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

        $response = $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/precipitation/refresh')
            ->assertStatus(202)
            ->assertJsonPath('data.requested', true)
            ->assertJsonPath('data.operation.dataset_keys.0', 'radar_forecast')
            ->assertJsonPath('data.operation.dataset_keys.1', 'seamless_precipitation_ensemble_forecast_probabilities')
            ->assertJsonPath('data.operation.state', 'queued')
            ->assertJsonPath('data.operation.progress_percent', 0);
        $operationId = $response->json('data.operation.id');

        Queue::assertPushed(RefreshWeatherDatasetOperation::class, function (RefreshWeatherDatasetOperation $job) use ($operationId): bool {
            return $job->operationId === $operationId
                && $job->connection === 'knmi_realtime'
                && $job->queue === 'knmi-realtime';
        });
        Queue::assertNotPushed(RefreshKnmiForecastDataset::class);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'weather.dataset.refresh_requested',
            'target_type' => WeatherDatasetOperation::class,
            'target_id' => $operationId,
        ]);
        $this->assertDatabaseHas('weather_dataset_operations', [
            'id' => $operationId,
            'dataset_key' => 'radar_forecast',
            'state' => WeatherDatasetOperation::STATE_QUEUED,
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

        Queue::assertNotPushed(RefreshWeatherDatasetOperation::class);
    }

    public function test_each_refreshable_dataset_has_a_traceable_allowlisted_update_endpoint(): void
    {
        Queue::fake();
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'open-data-public-key-123456',
            'is_sensitive' => true,
        ]);
        $viewer = $this->user('knmi-dataset-viewer@example.test', []);
        $manager = $this->user('knmi-dataset-refresh@example.test', ['settings.manage']);

        $this->postJson('/api/admin/knmi/datasets/radar_forecast/refresh')->assertUnauthorized();
        $this->asAdminClient($viewer)
            ->postJson('/api/admin/knmi/datasets/radar_forecast/refresh')
            ->assertForbidden();
        $response = $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/radar_forecast/refresh')
            ->assertStatus(202)
            ->assertJsonPath('data.dataset_key', 'radar_forecast')
            ->assertJsonPath('data.operation.state', 'queued')
            ->assertJsonPath('data.operation.stage', 'queued');
        $operationId = $response->json('data.operation.id');
        Queue::assertPushed(
            RefreshWeatherDatasetOperation::class,
            fn (RefreshWeatherDatasetOperation $job): bool => $job->operationId === $operationId,
        );
        $poll = $this->asAdminClient($manager)->getJson('/api/admin/knmi')->assertOk();
        $radar = collect($poll->json('data.datasets'))->firstWhere('key', 'radar_forecast');
        $this->assertSame($operationId, $radar['operation']['id']);
        $this->assertSame('queued', $radar['operation']['state']);
        $this->assertSame(0, $radar['operation']['progress_percent']);

        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/seamless_precipitation_ensemble_forecast_probabilities/refresh')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'knmi_dataset_refresh_conflict');
        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/open_meteo/refresh')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'knmi_dataset_not_refreshable');
        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/not_a_dataset/refresh')
            ->assertMethodNotAllowed();
    }

    public function test_generic_dataset_endpoint_queues_harmonie_and_eumetsat_on_their_real_workers(): void
    {
        Queue::fake();
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'open-data-public-key-123456',
            'is_sensitive' => true,
        ]);
        $manager = $this->user('knmi-other-dataset-refresh@example.test', ['settings.manage']);

        $harmonie = $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/harmonie_arome_cy43_p1/refresh')
            ->assertStatus(202)
            ->assertJsonPath('data.dataset_key', 'harmonie_arome_cy43_p1')
            ->assertJsonPath('data.operation.state', 'queued');
        $harmonieOperationId = $harmonie->json('data.operation.id');
        Queue::assertPushed(
            RefreshKnmiForecastDataset::class,
            fn (RefreshKnmiForecastDataset $job): bool => $job->operationId === $harmonieOperationId,
        );

        $lightning = $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/eumetsat_mtg_li/refresh')
            ->assertStatus(202)
            ->assertJsonPath('data.dataset_key', 'eumetsat_mtg_li')
            ->assertJsonPath('data.operation.dataset_keys.0', 'eumetsat_mtg_li')
            ->assertJsonPath('data.operation.state', 'queued');
        $lightningOperationId = $lightning->json('data.operation.id');
        Queue::assertPushed(
            RefreshWeatherDatasetOperation::class,
            fn (RefreshWeatherDatasetOperation $job): bool => $job->operationId === $lightningOperationId,
        );
        $this->assertDatabaseHas('weather_dataset_operations', [
            'id' => $lightningOperationId,
            'dataset_key' => 'eumetsat_mtg_li',
            'state' => WeatherDatasetOperation::STATE_QUEUED,
        ]);
    }

    public function test_realtime_knmi_refresh_is_rejected_before_queueing_without_open_data_configuration(): void
    {
        Queue::fake();
        $manager = $this->user('knmi-dataset-unconfigured@example.test', ['settings.manage']);

        foreach ([
            'radar_forecast',
            'seamless_precipitation_ensemble_forecast_probabilities',
        ] as $dataset) {
            $this->asAdminClient($manager)
                ->postJson("/api/admin/knmi/datasets/{$dataset}/refresh")
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'knmi_dataset_not_refreshable')
                ->assertJsonPath('error.message', 'Een aparte KNMI Open Data API-sleutel is vereist.');
        }

        Queue::assertNotPushed(RefreshWeatherDatasetOperation::class);
        $this->assertDatabaseCount('weather_dataset_operations', 0);
    }

    public function test_queue_outage_returns_service_unavailable_and_persists_auditable_failure(): void
    {
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'open-data-public-key-123456',
            'is_sensitive' => true,
        ]);
        $manager = $this->user('knmi-queue-outage@example.test', ['settings.manage']);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->andThrow(new \RuntimeException('redis-host-secret.example:6379'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        Log::spy();

        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/radar_forecast/refresh')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'knmi_dataset_queue_unavailable');
        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/precipitation/refresh')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'knmi_dataset_queue_unavailable');

        $this->assertDatabaseCount('weather_dataset_operations', 2);
        $this->assertSame(
            2,
            WeatherDatasetOperation::query()
                ->where('state', WeatherDatasetOperation::STATE_FAILED)
                ->where('error_code', 'queue_unavailable')
                ->whereNull('active_key')
                ->count(),
        );
        $this->assertSame(
            2,
            DB::table('audit_logs')
                ->where('action', 'weather.dataset.refresh_failed')
                ->count(),
        );
        Log::shouldHaveReceived('error')
            ->twice()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Weather dataset refresh failed.'
                && $context['dataset_key'] === 'radar_forecast'
                && $context['error_code'] === 'queue_unavailable'
                && $context['exception_class'] === \RuntimeException::class
                && is_string($context['operation_id'] ?? null)
                && ! in_array('redis-host-secret.example:6379', $context, true));
    }

    public function test_scheduled_queue_outage_fails_the_commands_instead_of_reporting_an_active_conflict(): void
    {
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'open-data-public-key-123456',
            'is_sensitive' => true,
        ]);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->andThrow(new \RuntimeException('simulated queue outage'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        Log::spy();

        $this->artisan('dis:refresh-knmi-precipitation-outlook')
            ->expectsOutputToContain('queue is unavailable')
            ->assertFailed();
        $this->artisan('dis:refresh-eumetsat-lightning')
            ->expectsOutputToContain('queue is unavailable')
            ->assertFailed();

        $this->assertSame(
            2,
            WeatherDatasetOperation::query()
                ->where('scheduled', true)
                ->where('state', WeatherDatasetOperation::STATE_FAILED)
                ->where('error_code', 'queue_unavailable')
                ->count(),
        );
        $this->assertSame(
            2,
            DB::table('audit_logs')
                ->where('action', 'weather.dataset.refresh_failed')
                ->count(),
        );
    }

    public function test_harmonie_queue_outage_is_not_masked_as_a_conflict_for_http_or_scheduler(): void
    {
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'open-data-public-key-123456',
            'is_sensitive' => true,
        ]);
        $manager = $this->user('knmi-harmonie-queue-outage@example.test', ['settings.manage']);
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->times(3)
            ->andThrow(new \RuntimeException('redis-credential-or-host-detail'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        Log::spy();

        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/refresh')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'knmi_dataset_queue_unavailable');
        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/harmonie_arome_cy43_p1/refresh')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'knmi_dataset_queue_unavailable');
        $this->artisan('dis:refresh-knmi-forecast')
            ->expectsOutputToContain('kon niet worden gestart')
            ->assertFailed();

        $this->assertSame(
            3,
            KnmiForecastOperation::query()
                ->where('state', KnmiForecastOperation::STATE_FAILED)
                ->where('error_code', 'queue_unavailable')
                ->whereNull('active_key')
                ->count(),
        );
        $this->assertSame(
            3,
            DB::table('audit_logs')
                ->where('action', 'weather.knmi.refresh_failed')
                ->count(),
        );
        Log::shouldHaveReceived('error')
            ->times(3)
            ->withArgs(fn (string $message, array $context): bool => $message === 'KNMI forecast refresh could not be queued.'
                && $context['error_code'] === 'queue_unavailable'
                && $context['exception_class'] === \RuntimeException::class
                && is_string($context['operation_id'] ?? null)
                && ! in_array('redis-credential-or-host-detail', $context, true));
    }

    public function test_scheduled_dataset_refreshes_are_traced_without_success_path_audit_spam(): void
    {
        Queue::fake();
        Log::spy();

        $operation = app(WeatherDatasetOperationService::class)->request(
            WeatherDatasetOperationService::EUMETSAT_LIGHTNING,
            scheduled: true,
        );

        $this->assertTrue($operation->scheduled);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'weather.dataset.refresh_scheduled',
            'target_id' => $operation->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'weather.dataset.refresh_requested',
            'target_id' => $operation->id,
        ]);

        (new RefreshWeatherDatasetOperation($operation->id))->failed(new \RuntimeException('scheduler-failure-detail'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'weather.dataset.refresh_failed',
            'target_id' => $operation->id,
        ]);
        $this->assertSame(WeatherDatasetOperation::STATE_FAILED, $operation->refresh()->state);
    }

    public function test_failed_realtime_worker_is_persisted_and_exposed_without_hiding_catalog_status(): void
    {
        Queue::fake();
        Log::spy();
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'open-data-public-key-123456',
            'is_sensitive' => true,
        ]);
        $manager = $this->user('knmi-worker-failure@example.test', ['settings.manage']);
        $response = $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/radar_forecast/refresh')
            ->assertStatus(202);
        $operationId = $response->json('data.operation.id');

        (new RefreshWeatherDatasetOperation($operationId))->failed(new \RuntimeException('secret-internal-detail'));

        $operation = WeatherDatasetOperation::query()->findOrFail($operationId);
        $this->assertSame(WeatherDatasetOperation::STATE_FAILED, $operation->state);
        $this->assertSame('worker_failed', $operation->error_code);
        $this->assertStringNotContainsString('secret-internal-detail', (string) $operation->error_message);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Weather dataset refresh failed.'
                && $context === [
                    'operation_id' => $operationId,
                    'dataset_key' => 'radar_forecast',
                    'error_code' => 'worker_failed',
                    'exception_class' => \RuntimeException::class,
                ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'weather.dataset.refresh_failed',
            'target_id' => $operationId,
        ]);

        $status = $this->asAdminClient($manager)->getJson('/api/admin/knmi')->assertOk();
        $radar = collect($status->json('data.datasets'))->firstWhere('key', 'radar_forecast');
        $this->assertSame('worker_failed', $radar['latest_error']['code']);
        $this->assertSame('failed', $radar['operation']['state']);
        $this->assertCount(7, $status->json('data.datasets'));
    }

    public function test_operational_pruning_bounds_terminal_weather_dataset_operations_only(): void
    {
        config()->set('dis.retention.weather_dataset_operations_days', 14);
        $oldTerminal = WeatherDatasetOperation::query()->create([
            'dataset_key' => 'eumetsat_mtg_li',
            'dataset_keys' => ['eumetsat_mtg_li'],
            'active_key' => null,
            'scheduled' => true,
            'state' => WeatherDatasetOperation::STATE_SUCCEEDED,
            'stage' => 'completed',
            'message' => 'Completed.',
            'progress_percent' => 100,
            'finished_at' => now()->subDays(20),
        ]);
        $recentTerminal = WeatherDatasetOperation::query()->create([
            'dataset_key' => 'eumetsat_mtg_li',
            'dataset_keys' => ['eumetsat_mtg_li'],
            'active_key' => null,
            'scheduled' => true,
            'state' => WeatherDatasetOperation::STATE_FAILED,
            'stage' => 'failed',
            'message' => 'Failed.',
            'progress_percent' => 25,
            'finished_at' => now()->subDays(5),
        ]);
        $activeOld = WeatherDatasetOperation::query()->create([
            'dataset_key' => 'radar_forecast',
            'dataset_keys' => [
                'radar_forecast',
                'seamless_precipitation_ensemble_forecast_probabilities',
            ],
            'active_key' => 'knmi-precipitation',
            'scheduled' => true,
            'state' => WeatherDatasetOperation::STATE_RUNNING,
            'stage' => 'importing',
            'message' => 'Running.',
            'progress_percent' => 25,
            'started_at' => now()->subDays(20),
        ]);
        foreach ([$oldTerminal, $activeOld] as $operation) {
            $operation->forceFill([
                'created_at' => now()->subDays(20),
                'updated_at' => now()->subDays(20),
            ])->save();
        }
        $recentTerminal->forceFill([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ])->save();

        $this->artisan('dis:prune-operational-data')->assertSuccessful();

        $this->assertDatabaseMissing('weather_dataset_operations', ['id' => $oldTerminal->id]);
        $this->assertDatabaseHas('weather_dataset_operations', ['id' => $recentTerminal->id]);
        $this->assertDatabaseHas('weather_dataset_operations', [
            'id' => $activeOld->id,
            'active_key' => 'knmi-precipitation',
        ]);
    }

    public function test_stale_recovery_respects_worker_and_single_queue_timing_budgets(): void
    {
        Queue::fake();
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'open-data-public-key-123456',
            'is_sensitive' => true,
        ]);
        $manager = $this->user('knmi-stale-budget@example.test', ['settings.manage']);
        $queued = WeatherDatasetOperation::query()->create([
            'dataset_key' => 'radar_forecast',
            'dataset_keys' => [
                'radar_forecast',
                'seamless_precipitation_ensemble_forecast_probabilities',
            ],
            'active_key' => 'knmi-precipitation',
            'scheduled' => true,
            'state' => WeatherDatasetOperation::STATE_QUEUED,
            'stage' => 'queued',
            'message' => 'Queued.',
            'progress_percent' => 0,
        ]);
        $queued->forceFill([
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ])->save();
        $running = WeatherDatasetOperation::query()->create([
            'dataset_key' => 'eumetsat_mtg_li',
            'dataset_keys' => ['eumetsat_mtg_li'],
            'active_key' => 'eumetsat-lightning',
            'scheduled' => true,
            'state' => WeatherDatasetOperation::STATE_RUNNING,
            'stage' => 'importing',
            'message' => 'Running.',
            'progress_percent' => 25,
            'started_at' => now()->subMinutes(22),
        ]);
        $running->forceFill([
            'created_at' => now()->subMinutes(23),
            'updated_at' => now()->subMinutes(22),
        ])->save();

        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/radar_forecast/refresh')
            ->assertStatus(409);
        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/eumetsat_mtg_li/refresh')
            ->assertStatus(409);
        $this->assertSame(WeatherDatasetOperation::STATE_QUEUED, $queued->refresh()->state);
        $this->assertSame(WeatherDatasetOperation::STATE_RUNNING, $running->refresh()->state);

        $queued->forceFill([
            'created_at' => now()->subMinutes(46),
            'updated_at' => now()->subMinutes(46),
        ])->save();
        $running->forceFill([
            'started_at' => now()->subMinutes(26),
            'updated_at' => now()->subMinutes(26),
        ])->save();

        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/radar_forecast/refresh')
            ->assertStatus(202);
        $this->asAdminClient($manager)
            ->postJson('/api/admin/knmi/datasets/eumetsat_mtg_li/refresh')
            ->assertStatus(202);
        $this->assertSame(WeatherDatasetOperation::STATE_FAILED, $queued->refresh()->state);
        $this->assertSame('operation_stale', $queued->error_code);
        $this->assertSame(WeatherDatasetOperation::STATE_FAILED, $running->refresh()->state);
        $this->assertSame('operation_stale', $running->error_code);
        $this->assertSame(2, WeatherDatasetOperation::query()->where('state', 'queued')->count());
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
        $token = $user->createToken('KNMI test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
