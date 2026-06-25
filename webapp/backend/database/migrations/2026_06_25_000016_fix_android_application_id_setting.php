<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('system_settings')
            ->where('key', 'updates.android.application_id')
            ->where('value', json_encode('nl.nationaaldroneteam.dis', JSON_THROW_ON_ERROR))
            ->update([
                'value' => json_encode('nl.wrdmarco.dis', JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'updates.android.application_id')
            ->where('value', json_encode('nl.wrdmarco.dis', JSON_THROW_ON_ERROR))
            ->update([
                'value' => json_encode('nl.nationaaldroneteam.dis', JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }
};
