<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const UPDATED_AT_INDEXES = [
        'speech_manifest_builds' => 'speech_manifest_queue_monitor_idx',
        'speech_previews' => 'speech_preview_queue_monitor_idx',
        'speech_prepared_phrases' => 'speech_prepared_queue_monitor_idx',
        'speech_cache_jobs' => 'speech_cache_job_queue_monitor_idx',
        'speech_model_installations' => 'speech_model_queue_monitor_idx',
        'incident_speech_preparations' => 'incident_speech_queue_monitor_idx',
    ];

    public function up(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->index('queue', 'failed_jobs_queue_monitor_idx');
        });

        Schema::table('dispatch_push_outbox', function (Blueprint $table): void {
            $table->index('updated_at', 'dispatch_push_outbox_recent_monitor_idx');
        });
        DB::statement(
            'CREATE INDEX dispatch_push_outbox_active_monitor_idx '
            .'ON dispatch_push_outbox (created_at DESC) '
            .'WHERE delivered_at IS NULL AND cancelled_at IS NULL',
        );

        Schema::table('speech_cache_entries', function (Blueprint $table): void {
            $table->index('created_at', 'speech_cache_queue_monitor_idx');
        });

        foreach (self::UPDATED_AT_INDEXES as $tableName => $indexName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->index('updated_at', $indexName);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::UPDATED_AT_INDEXES, true) as $tableName => $indexName) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        }

        Schema::table('speech_cache_entries', function (Blueprint $table): void {
            $table->dropIndex('speech_cache_queue_monitor_idx');
        });

        DB::statement('DROP INDEX IF EXISTS dispatch_push_outbox_active_monitor_idx');
        Schema::table('dispatch_push_outbox', function (Blueprint $table): void {
            $table->dropIndex('dispatch_push_outbox_recent_monitor_idx');
        });

        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->dropIndex('failed_jobs_queue_monitor_idx');
        });
    }
};
