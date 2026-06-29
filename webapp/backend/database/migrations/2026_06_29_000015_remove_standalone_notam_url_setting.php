<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_settings')->where('key', 'drone.notam_url')->delete();
    }

    public function down(): void
    {
        //
    }
};
