<?php

namespace Tests\Feature;

use App\Jobs\RefreshKnmiForecastDataset;
use App\Models\AuditLog;
use App\Models\KnmiForecastOperation;
use App\Models\KnmiForecastSnapshot;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class KnmiForecastRestoreTest extends TestCase
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

    public function test_restore_reconciliation_clears_cache_state_and_queues_a_fresh_configured_import(): void
    {
        Queue::fake();
        SystemSetting::query()->create([
            'key' => 'weather.knmi_open_data_api_key',
            'value' => 'restore-open-data-key-123456',
            'is_sensitive' => true,
        ]);
        $snapshot = $this->snapshot();
        KnmiForecastOperation::query()->create([
            'state' => KnmiForecastOperation::STATE_SUCCEEDED,
            'stage' => 'completed',
            'message' => 'Oude import.',
            'progress_percent' => 100,
            'downloaded_bytes' => 861_009_920,
            'total_bytes' => 861_009_920,
            'source_filename' => $snapshot->source_filename,
            'snapshot_id' => $snapshot->id,
            'finished_at' => now()->subHour(),
        ]);
        KnmiForecastOperation::query()->create([
            'state' => KnmiForecastOperation::STATE_RUNNING,
            'stage' => 'downloading',
            'active_key' => KnmiForecastOperation::ACTIVE_KEY,
            'message' => 'Herstelde bewerking.',
            'progress_percent' => 20,
            'downloaded_bytes' => 100,
            'total_bytes' => 500,
            'started_at' => now()->subMinutes(10),
        ]);

        $this->artisan('dis:reconcile-knmi-after-restore')->assertSuccessful();

        $this->assertDatabaseCount('knmi_forecast_snapshots', 0);
        $this->assertDatabaseCount('knmi_forecast_operations', 1);
        $replacement = KnmiForecastOperation::query()->sole();
        $this->assertSame(KnmiForecastOperation::STATE_QUEUED, $replacement->state);
        $this->assertSame(KnmiForecastOperation::ACTIVE_KEY, $replacement->active_key);
        $this->assertNull($replacement->snapshot_id);
        Queue::assertPushed(RefreshKnmiForecastDataset::class, function (RefreshKnmiForecastDataset $job) use ($replacement): bool {
            return $job->operationId === $replacement->id
                && $job->connection === 'knmi'
                && $job->queue === 'knmi';
        });
        $audit = AuditLog::query()
            ->where('action', 'weather.knmi.cache_reconciled_after_restore')
            ->sole();
        $this->assertSame(2, $audit->metadata['operations_cleared']);
        $this->assertSame(1, $audit->metadata['snapshots_cleared']);
        $this->assertTrue($audit->metadata['refresh_required']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'weather.knmi.refresh_scheduled',
            'target_id' => $replacement->id,
        ]);
    }

    public function test_restore_reconciliation_leaves_no_cache_claim_when_open_data_is_not_configured(): void
    {
        Queue::fake();
        $snapshot = $this->snapshot();
        KnmiForecastOperation::query()->create([
            'state' => KnmiForecastOperation::STATE_SUCCEEDED,
            'stage' => 'completed',
            'message' => 'Oude import.',
            'progress_percent' => 100,
            'downloaded_bytes' => 861_009_920,
            'total_bytes' => 861_009_920,
            'source_filename' => $snapshot->source_filename,
            'snapshot_id' => $snapshot->id,
            'finished_at' => now()->subHour(),
        ]);

        $this->artisan('dis:reconcile-knmi-after-restore')
            ->expectsOutputToContain('Open Data is niet geconfigureerd')
            ->assertSuccessful();

        $this->assertDatabaseCount('knmi_forecast_snapshots', 0);
        $this->assertDatabaseCount('knmi_forecast_operations', 0);
        Queue::assertNothingPushed();
        $audit = AuditLog::query()
            ->where('action', 'weather.knmi.cache_reconciled_after_restore')
            ->sole();
        $this->assertFalse($audit->metadata['refresh_required']);
    }

    private function snapshot(): KnmiForecastSnapshot
    {
        return KnmiForecastSnapshot::query()->create([
            'dataset' => 'harmonie_arome_cy43_p1',
            'dataset_version' => '1.0',
            'source_filename' => 'HARM43_V1_P1_2026072015.tar',
            'source_size_bytes' => 861_009_920,
            'source_sha256' => str_repeat('a', 64),
            'model_run_at' => now()->subHours(3),
            'forecast_start_at' => now()->subHours(3),
            'forecast_end_at' => now()->addHours(57),
            'member_count' => 61,
            'release_directory' => 'releases/'.str()->ulid(),
            'manifest' => ['members' => []],
            'active_key' => KnmiForecastSnapshot::ACTIVE_KEY,
            'activated_at' => now()->subHour(),
        ]);
    }
}
