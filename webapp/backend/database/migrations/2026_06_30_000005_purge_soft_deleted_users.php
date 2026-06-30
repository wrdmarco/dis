<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('deleted_at')
            ->delete();
    }

    public function down(): void
    {
        // Purged soft-deleted users cannot be restored.
    }
};
