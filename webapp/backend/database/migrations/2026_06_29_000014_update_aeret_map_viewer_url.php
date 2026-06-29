<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $value = json_encode('https://aeret.kaartviewer.nl/?@dpf_basic', JSON_THROW_ON_ERROR);

        $updated = DB::table('system_settings')
            ->where('key', 'drone.aeret_map_url')
            ->update([
                'value' => $value,
                'is_sensitive' => false,
                'updated_by' => null,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            DB::table('system_settings')->insert([
                'key' => 'drone.aeret_map_url',
                'value' => $value,
                'is_sensitive' => false,
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'drone.aeret_map_url')
            ->where('value', json_encode('https://aeret.kaartviewer.nl/?@dpf_basic', JSON_THROW_ON_ERROR))
            ->update([
                'value' => json_encode('https://dronepreflight.nl/', JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
