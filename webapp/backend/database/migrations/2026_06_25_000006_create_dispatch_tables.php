<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dispatch_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignUlid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('target_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('status')->index();
            $table->string('priority')->index();
            $table->text('message');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->index(['incident_id', 'status']);
        });

        Schema::create('dispatch_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('dispatch_request_id')->constrained('dispatch_requests')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('response_status')->default('pending')->index();
            $table->text('response_note')->nullable();
            $table->timestampTz('notified_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['dispatch_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_recipients');
        Schema::dropIfExists('dispatch_requests');
    }
};

