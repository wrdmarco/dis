<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_incident_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->string('user_email')->nullable();
            $table->string('status')->default('draft')->index();
            $table->text('summary')->nullable();
            $table->text('observations')->nullable();
            $table->text('actions_taken')->nullable();
            $table->text('result')->nullable();
            $table->text('issues')->nullable();
            $table->text('equipment_used')->nullable();
            $table->unsignedSmallInteger('flight_minutes')->nullable();
            $table->timestampTz('prepared_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampsTz();

            $table->unique(['incident_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_incident_reports');
    }
};
