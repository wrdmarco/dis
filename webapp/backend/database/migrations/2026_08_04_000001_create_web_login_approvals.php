<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->jsonb('capabilities')->default('[]')->after('sdk_version');
        });

        Schema::create('web_login_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('browser_session_hash', 64)->nullable()->unique();
            $table->unsignedBigInteger('auth_session_version');
            $table->string('status', 20)->default('pending')->index();
            $table->string('verification_number', 3);
            $table->string('request_device', 160);
            $table->string('request_ip', 64)->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('denied_at')->nullable();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUlid('approved_by_fcm_token_id')
                ->nullable()
                ->constrained('fcm_tokens')
                ->nullOnDelete();
            $table->foreignUlid('approved_by_personal_access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->timestampsTz();
            $table->index(['user_id', 'status', 'expires_at']);
        });

        Schema::create('web_login_approval_recipients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('web_login_approval_id')
                ->constrained('web_login_approvals')
                ->cascadeOnDelete();
            $table->foreignUlid('fcm_token_id')
                ->nullable()
                ->constrained('fcm_tokens')
                ->nullOnDelete();
            $table->foreignUlid('personal_access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->string('delivery_status', 20)->default('queued');
            $table->timestampTz('last_sent_at')->nullable();
            $table->timestampTz('delivery_failed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['web_login_approval_id', 'fcm_token_id'], 'web_login_approval_recipient_device_unique');
            $table->index(
                ['fcm_token_id', 'personal_access_token_id'],
                'web_login_approval_recipient_session_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_login_approval_recipients');
        Schema::dropIfExists('web_login_approvals');

        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->dropColumn('capabilities');
        });
    }
};
