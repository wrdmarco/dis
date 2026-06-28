<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('roles')
            ->whereIn('name', ['support-staff', 'auditor'])
            ->update(['can_use_admin_app' => true]);
    }

    public function down(): void
    {
        DB::table('roles')
            ->whereIn('name', ['support-staff', 'auditor'])
            ->update(['can_use_admin_app' => false]);
    }
};
