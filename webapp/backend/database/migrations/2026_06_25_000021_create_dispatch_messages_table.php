<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dispatch_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('dispatch_request_id')->constrained('dispatch_requests')->cascadeOnDelete();
            $table->foreignUlid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['dispatch_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_messages');
    }
};
