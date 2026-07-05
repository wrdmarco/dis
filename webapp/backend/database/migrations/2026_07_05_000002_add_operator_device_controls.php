<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_operator_devices')->default(1)->after('push_enabled');
        });

        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->string('client_type', 30)->default('operator')->after('platform')->index();
            $table->string('device_type', 30)->nullable()->after('device_id');
            $table->string('device_name', 120)->nullable()->after('device_type');
            $table->index(['client_type', 'is_active', 'last_seen_at']);
        });

        DB::table('fcm_tokens')
            ->whereNull('client_type')
            ->orWhere('client_type', '')
            ->update(['client_type' => 'operator']);

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'devices.heartbeat_interval_minutes'],
            [
                'value' => json_encode(15),
                'is_sensitive' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table): void {
            $table->dropIndex(['client_type', 'is_active', 'last_seen_at']);
            $table->dropIndex(['client_type']);
            $table->dropColumn(['client_type', 'device_type', 'device_name']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('max_operator_devices');
        });

        DB::table('system_settings')->where('key', 'devices.heartbeat_interval_minutes')->delete();
    }
};
