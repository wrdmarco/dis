<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_request_status_histories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_request_id')->constrained('product_requests')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_name_snapshot', 255);
            $table->timestampsTz();

            $table->index(['product_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_request_status_histories');
    }
};
