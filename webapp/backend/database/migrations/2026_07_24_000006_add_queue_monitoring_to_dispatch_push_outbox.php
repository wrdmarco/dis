<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_push_outbox', function (Blueprint $table): void {
            $table->timestampTz('processing_started_at')->nullable()->after('queued_at');
            $table->timestampTz('retry_at')->nullable()->after('processing_started_at');
            $table->index(
                ['processing_started_at', 'retry_at', 'delivered_at', 'cancelled_at'],
                'dispatch_push_outbox_monitor_idx',
            );
        });
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->char('revocation_generation', 26)->nullable()->after('revoked_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->dropIndex(['revocation_generation']);
            $table->dropColumn('revocation_generation');
        });
        Schema::table('dispatch_push_outbox', function (Blueprint $table): void {
            $table->dropIndex('dispatch_push_outbox_monitor_idx');
            $table->dropColumn(['processing_started_at', 'retry_at']);
        });
    }
};
