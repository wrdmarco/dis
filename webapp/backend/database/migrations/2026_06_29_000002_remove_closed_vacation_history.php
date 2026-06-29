<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_vacations')
            ->whereIn('status', ['cancelled', 'completed'])
            ->delete();

        DB::table('user_vacations')
            ->whereDate('ends_at', '<', now()->toDateString())
            ->delete();
    }

    public function down(): void
    {
        // Vacation history is intentionally not restored.
    }
};
