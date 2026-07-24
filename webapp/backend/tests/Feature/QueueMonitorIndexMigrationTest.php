<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class QueueMonitorIndexMigrationTest extends TestCase
{
    use DatabaseMigrations;

    /** @var list<string> */
    private const INDEXES = [
        'failed_jobs_queue_monitor_idx',
        'dispatch_push_outbox_recent_monitor_idx',
        'dispatch_push_outbox_active_monitor_idx',
        'speech_cache_queue_monitor_idx',
        'speech_manifest_queue_monitor_idx',
        'speech_preview_queue_monitor_idx',
        'speech_prepared_queue_monitor_idx',
        'speech_cache_job_queue_monitor_idx',
        'speech_model_queue_monitor_idx',
        'incident_speech_queue_monitor_idx',
    ];

    public function test_monitor_indexes_match_or_query_branches_and_down_is_symmetric(): void
    {
        $definitions = $this->definitions();
        $this->assertCount(count(self::INDEXES), $definitions);
        $this->assertStringContainsString(
            '(created_at)',
            $definitions->get('speech_cache_queue_monitor_idx'),
        );
        $this->assertStringContainsString(
            '(queue)',
            $definitions->get('failed_jobs_queue_monitor_idx'),
        );
        foreach ([
            'dispatch_push_outbox_recent_monitor_idx',
            'speech_manifest_queue_monitor_idx',
            'speech_preview_queue_monitor_idx',
            'speech_prepared_queue_monitor_idx',
            'speech_cache_job_queue_monitor_idx',
            'speech_model_queue_monitor_idx',
            'incident_speech_queue_monitor_idx',
        ] as $indexName) {
            $this->assertStringContainsString('(updated_at)', $definitions->get($indexName));
        }
        $this->assertStringContainsString(
            'WHERE ((delivered_at IS NULL) AND (cancelled_at IS NULL))',
            $definitions->get('dispatch_push_outbox_active_monitor_idx'),
        );

        $migration = require database_path('migrations/2026_07_24_000008_add_queue_monitor_indexes.php');
        $migration->down();
        try {
            $this->assertCount(0, $this->definitions());
        } finally {
            $migration->up();
        }
        $this->assertCount(count(self::INDEXES), $this->definitions());
    }

    public function test_stale_active_reconciliation_has_status_timestamp_index(): void
    {
        $definition = DB::table('pg_indexes')
            ->whereRaw('schemaname = current_schema()')
            ->where('indexname', 'push_queue_work_stale_idx')
            ->value('indexdef');

        $this->assertIsString($definition);
        $this->assertStringContainsString('(status, updated_at)', $definition);
    }

    /** @return Collection<string, string> */
    private function definitions(): Collection
    {
        return DB::table('pg_indexes')
            ->whereRaw('schemaname = current_schema()')
            ->whereIn('indexname', self::INDEXES)
            ->pluck('indexdef', 'indexname');
    }
}
