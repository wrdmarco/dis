<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('asset_tag')->unique();
            $table->string('name');
            $table->string('type')->index();
            $table->string('status')->index();
            $table->string('serial_number')->nullable()->unique();
            $table->date('maintenance_due_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('asset_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUlid('incident_id')->nullable()->constrained('incidents')->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->useCurrent();
            $table->timestampTz('released_at')->nullable();
            $table->index(['asset_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
        Schema::dropIfExists('assets');
    }
};

