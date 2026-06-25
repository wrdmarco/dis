<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
            return;
        }

        DB::statement('ALTER TABLE users ALTER COLUMN two_factor_recovery_codes TYPE TEXT USING two_factor_recovery_codes::text');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
            return;
        }

        DB::statement('ALTER TABLE users ALTER COLUMN two_factor_recovery_codes TYPE JSONB USING CASE WHEN two_factor_recovery_codes IS NULL THEN NULL ELSE to_jsonb(two_factor_recovery_codes) END');
    }
};
