<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knmi_forecast_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('dataset', 120);
            $table->string('dataset_version', 24);
            $table->string('source_filename', 160)->index();
            $table->unsignedBigInteger('source_size_bytes');
            $table->char('source_sha256', 64);
            $table->timestampTz('model_run_at');
            $table->timestampTz('forecast_start_at');
            $table->timestampTz('forecast_end_at');
            $table->unsignedSmallInteger('member_count');
            $table->string('release_directory', 200)->unique();
            $table->json('manifest');
            $table->string('active_key', 32)->nullable()->unique();
            $table->timestampTz('activated_at');
            $table->timestampsTz();
            $table->index(['activated_at', 'id']);
        });

        Schema::create('knmi_forecast_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('state', 24)->index();
            $table->string('stage', 32);
            $table->string('active_key', 32)->nullable()->unique();
            $table->text('message');
            $table->unsignedSmallInteger('progress_percent')->nullable();
            $table->unsignedBigInteger('downloaded_bytes')->default(0);
            $table->unsignedBigInteger('total_bytes')->nullable();
            $table->string('source_filename', 160)->nullable();
            $table->boolean('unchanged')->default(false);
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('snapshot_id')->nullable()->constrained('knmi_forecast_snapshots')->nullOnDelete();
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knmi_forecast_operations');
        Schema::dropIfExists('knmi_forecast_snapshots');
    }
};
