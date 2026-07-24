<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_alert_schedule_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('run_key', 40)->unique();
            $table->timestampTz('scheduled_for')->index();
            $table->timestampTz('retry_until')->index();
            $table->text('message');
            $table->text('speech_lines');
            $table->char('template_checksum', 64);
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('expired_count')->default(0);
            $table->timestampTz('initialized_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('test_alert_schedule_deliveries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('test_alert_schedule_run_id');
            $table->foreign('test_alert_schedule_run_id', 'test_alert_schedule_delivery_run_fk')
                ->references('id')
                ->on('test_alert_schedule_runs')
                ->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('dispatch_request_id')->nullable()
                ->constrained('dispatch_requests')
                ->nullOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(
                ['test_alert_schedule_run_id', 'user_id'],
                'test_alert_schedule_delivery_user_unique',
            );
            $table->unique('dispatch_request_id');
            $table->index(
                ['test_alert_schedule_run_id', 'status'],
                'test_alert_schedule_delivery_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_alert_schedule_deliveries');
        Schema::dropIfExists('test_alert_schedule_runs');
    }
};
