<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class QueueMonitorIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const INDEXES = [
        'failed_jobs_queue_monitor_idx',
        'dispatch_push_outbox_recent_monitor_idx',
        'dispatch_push_outbox_active_monitor_idx',
    ];

    public function test_monitor_indexes_match_the_remaining_queue_query_branches(): void
    {
        $definitions = $this->definitions();
        $this->assertCount(count(self::INDEXES), $definitions);
        $this->assertStringContainsString(
            '(queue)',
            $definitions->get('failed_jobs_queue_monitor_idx'),
        );
        $this->assertStringContainsString(
            '(updated_at)',
            $definitions->get('dispatch_push_outbox_recent_monitor_idx'),
        );
        $this->assertStringContainsString(
            'WHERE ((delivered_at IS NULL) AND (cancelled_at IS NULL))',
            $definitions->get('dispatch_push_outbox_active_monitor_idx'),
        );
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
