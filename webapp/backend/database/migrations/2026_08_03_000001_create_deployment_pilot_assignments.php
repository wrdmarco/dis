<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_pilot_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('deployment_id')->constrained('deployments')->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name');
            $table->string('user_email')->nullable();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_by_name')->nullable();
            $table->string('assigned_by_email')->nullable();
            $table->text('reason');
            $table->timestampTz('assigned_at');
            $table->timestampsTz();

            $table->unique(['deployment_id', 'user_id']);
            $table->index(['deployment_id', 'assigned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_pilot_assignments');
    }
};
