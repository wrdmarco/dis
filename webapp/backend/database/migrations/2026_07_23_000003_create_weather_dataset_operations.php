<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_dataset_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('dataset_key', 100)->index();
            $table->json('dataset_keys');
            $table->string('active_key', 100)->nullable()->unique();
            $table->boolean('scheduled')->default(false);
            $table->string('state', 24)->index();
            $table->string('stage', 40);
            $table->text('message');
            $table->unsignedSmallInteger('progress_percent')->nullable();
            $table->json('result')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['dataset_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_dataset_operations');
    }
};
