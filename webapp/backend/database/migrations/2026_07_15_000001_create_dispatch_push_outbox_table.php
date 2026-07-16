<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_push_outbox', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('deduplication_key', 64)->unique();
            $table->foreignUlid('dispatch_request_id')->constrained('dispatch_requests')->cascadeOnDelete();
            $table->foreignUlid('fcm_token_id')->constrained('fcm_tokens')->cascadeOnDelete();
            $table->string('message_type');
            $table->string('title');
            $table->text('body');
            $table->json('data');
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestampsTz();
            $table->index(['queued_at', 'delivered_at', 'cancelled_at', 'available_at'], 'dispatch_push_outbox_pending_idx');
            $table->index(['dispatch_request_id', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_push_outbox');
    }
};
