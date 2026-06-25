<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('fcm_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_id');
            $table->text('token');
            $table->string('platform')->default('android')->index();
            $table->string('app_version')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'device_id']);
        });

        Schema::create('push_delivery_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('fcm_token_id')->nullable()->constrained('fcm_tokens')->nullOnDelete();
            $table->foreignUlid('dispatch_request_id')->nullable()->constrained('dispatch_requests')->nullOnDelete();
            $table->string('message_type')->index();
            $table->string('status')->index();
            $table->string('provider_message_id')->nullable();
            $table->text('error_code')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });

        Schema::create('location_sharing_consents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('consented_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['incident_id', 'user_id']);
        });

        Schema::create('location_updates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('incident_id')->constrained('incidents')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->timestampTz('recorded_at')->index();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['incident_id', 'recorded_at']);
        });

        Schema::create('app_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('platform')->default('android')->index();
            $table->string('version_name');
            $table->unsignedInteger('version_code');
            $table->string('status')->index();
            $table->string('artifact_sha256')->nullable();
            $table->string('download_url')->nullable();
            $table->text('release_notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['platform', 'version_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
        Schema::dropIfExists('location_updates');
        Schema::dropIfExists('location_sharing_consents');
        Schema::dropIfExists('push_delivery_logs');
        Schema::dropIfExists('fcm_tokens');
    }
};

