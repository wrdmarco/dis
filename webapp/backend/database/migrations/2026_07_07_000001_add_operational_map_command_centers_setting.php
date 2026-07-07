<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('system_settings')->where('key', 'operational_map.command_centers')->exists()) {
            return;
        }

        $now = Carbon::now();

        DB::table('system_settings')->insert([
            'key' => 'operational_map.command_centers',
            'value' => json_encode([], JSON_THROW_ON_ERROR),
            'is_sensitive' => false,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'operational_map.command_centers')
            ->delete();
    }
};
