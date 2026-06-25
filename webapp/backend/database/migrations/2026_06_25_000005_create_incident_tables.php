<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('reference')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority')->index();
            $table->string('status')->index();
            $table->string('location_label')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('coordinator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['status', 'priority']);
        });

        Schema::create('incident_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('assignment_role')->index();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->useCurrent();
            $table->timestampTz('released_at')->nullable();
            $table->unique(['incident_id', 'user_id', 'assignment_role']);
        });

        Schema::create('incident_status_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status')->index();
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_status_history');
        Schema::dropIfExists('incident_assignments');
        Schema::dropIfExists('incidents');
    }
};

