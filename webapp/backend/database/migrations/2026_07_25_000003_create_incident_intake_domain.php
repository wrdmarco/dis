<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_intake_workflow_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedInteger('version')->nullable()->unique();
            $table->string('status', 16)->index();
            $table->string('draft_marker', 16)->nullable()->unique();
            $table->unsignedInteger('lock_version')->default(1);
            $table->jsonb('configuration');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('incident_intake_dossiers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workflow_revision_id')->constrained('incident_intake_workflow_revisions')->restrictOnDelete();
            $table->foreignUlid('incident_id')->nullable()->unique()->constrained('incidents')->cascadeOnDelete();
            $table->string('status', 16)->index();
            $table->string('subject_type', 16)->index();
            $table->jsonb('answers')->default('{}');
            $table->jsonb('triage');
            $table->string('recommended_priority', 16)->nullable();
            $table->string('decided_priority', 16)->nullable();
            $table->text('priority_decision_reason')->nullable();
            $table->string('selected_deployment_profile_id', 80)->nullable();
            $table->jsonb('selected_deployment_proposal')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('close_reason')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('incident_intake_mutations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('dossier_id')->constrained('incident_intake_dossiers')->cascadeOnDelete();
            $table->foreignUlid('actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_mutation_id', 120);
            $table->string('operation', 40);
            $table->char('request_hash', 64);
            $table->jsonb('response_payload');
            $table->timestampTz('created_at');
            $table->unique(['actor_id', 'client_mutation_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_intake_mutations');
        Schema::dropIfExists('incident_intake_dossiers');
        Schema::dropIfExists('incident_intake_workflow_revisions');
    }
};
