<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            WITH ranked_tokens AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY user_id, device_id, client_type
                        ORDER BY last_seen_at DESC NULLS LAST, updated_at DESC, created_at DESC, id DESC
                    ) AS row_number
                FROM fcm_tokens
                WHERE is_active = true
            )
            UPDATE fcm_tokens
            SET is_active = false,
                revoked_at = COALESCE(revoked_at, NOW()),
                updated_at = NOW()
            WHERE id IN (
                SELECT id
                FROM ranked_tokens
                WHERE row_number > 1
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS fcm_tokens_active_device_unique
            ON fcm_tokens (user_id, device_id, client_type)
            WHERE is_active = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fcm_tokens_active_device_unique');
    }
};
