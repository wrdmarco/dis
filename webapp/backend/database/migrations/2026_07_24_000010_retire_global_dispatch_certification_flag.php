<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('certifications')
            ->where('is_required_for_dispatch', true)
            ->update(['is_required_for_dispatch' => false]);
    }

    public function down(): void
    {
        // The previous global requirement cannot be reconstructed safely.
        // Team-specific certification links remain the source of truth.
    }
};
