<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speech_model_installations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('catalog_key', 80)->index();
            $table->string('revision', 160);
            $table->char('weights_sha256', 64);
            $table->string('status', 24)->index();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('license_confirmed_at')->nullable();
            $table->timestampTz('installed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['catalog_key', 'revision', 'weights_sha256'], 'speech_model_installation_identity_unique');
        });

        Schema::create('speech_runtime_states', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignUlid('active_installation_id')->nullable()
                ->constrained('speech_model_installations')->nullOnDelete();
            $table->string('active_model_id', 80)->nullable();
            $table->char('install_claim_token', 26)->nullable();
            $table->timestampTz('install_started_at')->nullable();
            $table->timestampTz('install_cancel_requested_at')->nullable();
            $table->timestampsTz();
        });
        DB::table('speech_runtime_states')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('speech_voice_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('locale', 16)->default('nl-NL');
            $table->text('transcript');
            $table->text('consent_statement');
            $table->timestampTz('consent_recorded_at');
            $table->string('sample_storage_path', 255)->unique();
            $table->char('sample_sha256', 64);
            $table->unsignedBigInteger('sample_byte_size');
            $table->unsignedInteger('reference_duration_ms');
            $table->unsignedInteger('consent_version')->default(1);
            $table->string('status', 24)->index();
            $table->string('error_code', 80)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        Schema::create('speech_audio_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('content_sha256', 64)->unique();
            $table->string('storage_path', 255)->unique();
            $table->string('mime_type', 40)->default('audio/mp4');
            $table->unsignedBigInteger('byte_size');
            $table->unsignedInteger('duration_ms');
            $table->timestampsTz();
        });

        Schema::create('speech_cache_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('cache_key', 64)->unique();
            $table->string('category', 24)->index();
            $table->foreignUlid('audio_asset_id')->nullable()->constrained('speech_audio_assets')->nullOnDelete();
            $table->foreignUlid('voice_profile_id')->nullable()->constrained('speech_voice_profiles')->nullOnDelete();
            $table->char('semantic_hmac', 64);
            $table->string('status', 24)->index();
            $table->string('error_code', 80)->nullable();
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->timestampTz('last_used_at')->nullable()->index();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
            $table->index(['category', 'status', 'last_used_at'], 'speech_cache_lru_idx');
        });

        Schema::create('speech_manifest_builds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('dispatch_request_id')->nullable()->constrained('dispatch_requests')->cascadeOnDelete();
            $table->foreignUlid('dispatch_recipient_id')->nullable()->constrained('dispatch_recipients')->cascadeOnDelete();
            $table->string('phase', 24)->index();
            $table->string('locale', 16)->default('nl-NL');
            $table->foreignUlid('model_installation_id')->constrained('speech_model_installations')->restrictOnDelete();
            $table->foreignUlid('voice_profile_id')->nullable()->constrained('speech_voice_profiles')->nullOnDelete();
            $table->string('voice_design_revision', 120)->nullable();
            $table->decimal('speed', 4, 2);
            $table->char('template_checksum', 64);
            $table->char('context_hmac', 64);
            $table->char('source_fingerprint_hmac', 64);
            $table->text('rendered_lines');
            $table->string('status', 24)->index();
            $table->string('error_code', 80)->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->timestampTz('release_deadline')->nullable()->index();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
            $table->unique(
                ['dispatch_recipient_id', 'phase', 'source_fingerprint_hmac'],
                'speech_manifest_build_recipient_identity_unique',
            );
        });

        Schema::create('speech_manifests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('speech_manifest_build_id')->unique()->constrained('speech_manifest_builds')->cascadeOnDelete();
            $table->foreignUlid('dispatch_request_id')->nullable()->constrained('dispatch_requests')->cascadeOnDelete();
            $table->foreignUlid('dispatch_recipient_id')->nullable()->constrained('dispatch_recipients')->cascadeOnDelete();
            $table->string('phase', 24)->index();
            $table->string('locale', 16)->default('nl-NL');
            $table->string('model_catalog_key', 80);
            $table->string('model_revision', 160);
            $table->char('model_weights_sha256', 64);
            $table->foreignUlid('voice_profile_id')->nullable()->constrained('speech_voice_profiles')->restrictOnDelete();
            $table->unsignedInteger('voice_consent_version')->nullable();
            $table->string('voice_design_revision', 120)->nullable();
            $table->decimal('speed', 4, 2);
            $table->char('template_checksum', 64);
            $table->char('context_hmac', 64);
            $table->char('manifest_sha256', 64)->unique();
            $table->foreignUlid('audio_asset_id')->constrained('speech_audio_assets')->restrictOnDelete();
            $table->unsignedSmallInteger('segment_count');
            $table->unsignedInteger('duration_ms');
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampTz('sealed_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('speech_manifest_segments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('speech_manifest_id')->constrained('speech_manifests')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('semantic_key', 80);
            $table->text('text');
            $table->char('text_hmac', 64);
            $table->char('cache_key', 64);
            $table->foreignUlid('audio_asset_id')->constrained('speech_audio_assets')->restrictOnDelete();
            $table->unsignedInteger('duration_ms');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['speech_manifest_id', 'sequence']);
        });

        Schema::create('speech_previews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('phase', 24);
            $table->string('status', 24)->index();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->text('rendered_lines');
            $table->foreignUlid('speech_manifest_build_id')->nullable()->constrained('speech_manifest_builds')->nullOnDelete();
            $table->foreignUlid('speech_manifest_id')->nullable()->constrained('speech_manifests')->nullOnDelete();
            $table->foreignUlid('audio_asset_id')->nullable()->constrained('speech_audio_assets')->nullOnDelete();
            $table->string('error_code', 80)->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('speech_cache_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('scope', 24);
            $table->string('status', 24)->index();
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('speech_cache_counters', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('hit_count')->default(0);
            $table->unsignedBigInteger('miss_count')->default(0);
            $table->timestampTz('last_pruned_at')->nullable();
            $table->timestampsTz();
        });
        DB::table('speech_cache_counters')->insert([
            'id' => 1,
            'hit_count' => 0,
            'miss_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->string('send_status', 32)->nullable()->index();
            $table->timestampTz('send_queued_at')->nullable();
            $table->timestampTz('send_release_deadline')->nullable()->index();
            $table->timestampTz('send_released_at')->nullable();
        });
        Schema::table('dispatch_push_outbox', function (Blueprint $table): void {
            $table->foreignUlid('speech_manifest_id')->nullable()->constrained('speech_manifests')->nullOnDelete();
            $table->string('release_reason', 40)->nullable();
            $table->index(['speech_manifest_id', 'delivered_at', 'cancelled_at'], 'dispatch_outbox_speech_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_push_outbox', function (Blueprint $table): void {
            $table->dropIndex('dispatch_outbox_speech_pending_idx');
            $table->dropConstrainedForeignId('speech_manifest_id');
            $table->dropColumn('release_reason');
        });
        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->dropColumn(['send_status', 'send_queued_at', 'send_release_deadline', 'send_released_at']);
        });
        Schema::dropIfExists('speech_cache_counters');
        Schema::dropIfExists('speech_cache_jobs');
        Schema::dropIfExists('speech_previews');
        Schema::dropIfExists('speech_manifest_segments');
        Schema::dropIfExists('speech_manifests');
        Schema::dropIfExists('speech_manifest_builds');
        Schema::dropIfExists('speech_cache_entries');
        Schema::dropIfExists('speech_audio_assets');
        Schema::dropIfExists('speech_voice_profiles');
        Schema::dropIfExists('speech_runtime_states');
        Schema::dropIfExists('speech_model_installations');
    }
};
