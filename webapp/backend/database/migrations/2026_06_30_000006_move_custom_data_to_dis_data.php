<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')
            ->where('key', 'backup.local_path')
            ->where('value', json_encode('/opt/dis/backup'))
            ->update([
                'value' => json_encode('/opt/dis-data/backup'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('key', 'backup.local_path')
            ->where('value', json_encode('/opt/dis-data/backup'))
            ->update([
                'value' => json_encode('/opt/dis/backup'),
                'updated_at' => now(),
            ]);
    }
};
