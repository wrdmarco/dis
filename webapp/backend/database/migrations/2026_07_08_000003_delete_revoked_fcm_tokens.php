<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('fcm_tokens')
            ->where('is_active', false)
            ->orWhereNotNull('revoked_at')
            ->delete();
    }

    public function down(): void
    {
        // Deleted push tokens cannot be restored.
    }
};
