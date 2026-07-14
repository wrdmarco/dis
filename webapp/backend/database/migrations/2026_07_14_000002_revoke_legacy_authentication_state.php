<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $revokedAt = now();

            DB::table('personal_access_tokens')->delete();
            DB::table('sessions')->delete();
            DB::table('password_reset_tokens')->delete();
            DB::table('mobile_pairing_codes')->delete();
            DB::table('system_settings')->where('key', 'developer.android_upload')->delete();
            DB::table('fcm_tokens')
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'revoked_at' => $revokedAt,
                    'updated_at' => $revokedAt,
                ]);
            DB::table('users')
                ->where('push_enabled', true)
                ->update([
                    'push_enabled' => false,
                    'updated_at' => $revokedAt,
                ]);
        });
    }

    public function down(): void
    {
        // Revoked credentials and sessions cannot be restored safely.
    }
};
