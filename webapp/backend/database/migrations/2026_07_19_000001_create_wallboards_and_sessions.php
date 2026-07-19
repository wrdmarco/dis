<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallboards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('layout', 40)->default('fullscreen_map')->index();
            $table->json('configuration');
            $table->unsignedBigInteger('config_version')->default(1);
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestampTz('paired_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('wallboard_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('wallboard_id')->constrained('wallboards')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('previous_token_hash', 64)->nullable();
            $table->timestampTz('previous_token_expires_at')->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('last_seen_at')->index();
            $table->timestampTz('last_rotated_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable()->index();
            $table->timestampsTz();

            $table->index(['wallboard_id', 'revoked_at']);
        });

        Schema::create('wallboard_pairing_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code_hash', 64)->unique();
            $table->string('secret_hash', 64)->unique();
            $table->string('device_name', 120)->nullable();
            $table->string('request_ip', 64)->nullable();
            $table->string('request_user_agent', 512)->nullable();
            $table->foreignUlid('wallboard_id')->nullable()->constrained('wallboards')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable()->index();
            $table->foreignUlid('wallboard_session_id')->nullable()->constrained('wallboard_sessions')->nullOnDelete();
            $table->timestampTz('consumed_at')->nullable()->index();
            $table->string('consumed_ip', 64)->nullable();
            $table->string('consumed_user_agent', 512)->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            $table->index(['wallboard_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallboard_pairing_requests');
        Schema::dropIfExists('wallboard_sessions');
        Schema::dropIfExists('wallboards');
    }
};
