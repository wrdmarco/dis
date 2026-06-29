<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->string('token_hash', 64)->nullable()->after('token');
            $table->string('device_manufacturer')->nullable()->after('device_id');
            $table->string('device_model')->nullable()->after('device_manufacturer');
            $table->string('android_version')->nullable()->after('device_model');
            $table->string('sdk_version')->nullable()->after('android_version');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->json('mail_preferences')->nullable()->after('push_enabled');
        });

        DB::table('fcm_tokens')
            ->select(['id', 'token'])
            ->orderBy('id')
            ->chunk(200, function ($tokens): void {
                foreach ($tokens as $token) {
                    DB::table('fcm_tokens')
                        ->where('id', $token->id)
                        ->update(['token_hash' => hash('sha256', (string) $token->token)]);
                }
            });

        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'device_id']);
            $table->index(['user_id', 'device_id']);
            $table->index(['user_id', 'token_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'token_hash']);
            $table->dropIndex(['user_id', 'device_id']);
            $table->unique(['user_id', 'device_id']);
            $table->dropColumn(['token_hash', 'device_manufacturer', 'device_model', 'android_version', 'sdk_version']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mail_preferences');
        });
    }
};
