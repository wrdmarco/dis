<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = Carbon::now();

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'security.mfa_issuer_name'],
            [
                'value' => json_encode('D.I.S', JSON_THROW_ON_ERROR),
                'is_sensitive' => false,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('system_settings')->where('key', 'security.mfa_issuer_name')->delete();
    }
};
