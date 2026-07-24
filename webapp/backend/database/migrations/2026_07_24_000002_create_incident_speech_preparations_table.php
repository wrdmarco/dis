<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_speech_preparations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('phase', 24);
            $table->char('source_fingerprint_hmac', 64);
            $table->string('status', 24)->index();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestampsTz();

            $table->unique(['incident_id', 'phase'], 'incident_speech_preparation_phase_unique');
            $table->index(
                ['incident_id', 'status', 'updated_at'],
                'incident_speech_preparation_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_speech_preparations');
    }
};
