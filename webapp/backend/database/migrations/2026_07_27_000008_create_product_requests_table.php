<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name_snapshot', 255);
            $table->string('type', 20);
            $table->string('title', 180);
            $table->text('description');
            $table->string('status', 20)->default('open');
            $table->text('resolution_note')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'updated_at']);
            $table->index(['type', 'updated_at']);
            $table->index(['requester_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_requests');
    }
};
