<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_queue_work_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('queue_job_id', 96)->unique();
            $table->string('safe_message_type', 48)->default('push_notification');
            $table->ulid('dispatch_push_outbox_id')->nullable()->index();
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at'], 'push_queue_work_monitor_idx');
            $table->index('updated_at', 'push_queue_work_recent_idx');
            $table->index(['status', 'updated_at'], 'push_queue_work_stale_idx');
            $table->index(['finished_at', 'status'], 'push_queue_work_prune_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_queue_work_items');
    }
};
