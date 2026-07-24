<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('fcm_tokens')
            ->whereNull('token_hash')
            ->select(['id', 'token'])
            ->orderBy('id')
            ->chunkById(200, function ($tokens): void {
                foreach ($tokens as $token) {
                    DB::table('fcm_tokens')
                        ->where('id', $token->id)
                        ->whereNull('token_hash')
                        ->update([
                            'token_hash' => hash('sha256', (string) $token->token),
                            'updated_at' => now(),
                        ]);
                }
            });

        DB::statement(<<<'SQL'
            UPDATE fcm_tokens
            SET is_active = false,
                revoked_at = COALESCE(revoked_at, NOW()),
                revocation_generation = NULL,
                updated_at = NOW()
            WHERE is_active = true
              AND NOT EXISTS (
                  SELECT 1
                  FROM personal_access_tokens
                  WHERE personal_access_tokens.id = fcm_tokens.personal_access_token_id
                    AND personal_access_tokens.tokenable_type = 'App\Models\User'
                    AND personal_access_tokens.tokenable_id = fcm_tokens.user_id
                    AND (
                        personal_access_tokens.expires_at IS NULL
                        OR personal_access_tokens.expires_at > NOW()
                    )
                    AND jsonb_exists(
                        COALESCE(personal_access_tokens.abilities, '[]')::jsonb,
                        'client:' || fcm_tokens.client_type
                    )
              )
        SQL);

        DB::statement(<<<'SQL'
            WITH ranked_tokens AS (
                SELECT
                    id,
                    ROW_NUMBER() OVER (
                        PARTITION BY platform, token_hash
                        ORDER BY last_seen_at DESC NULLS LAST, updated_at DESC, created_at DESC, id DESC
                    ) AS row_number
                FROM fcm_tokens
                WHERE is_active = true
                  AND token_hash IS NOT NULL
            )
            UPDATE fcm_tokens
            SET is_active = false,
                revoked_at = COALESCE(revoked_at, NOW()),
                revocation_generation = NULL,
                updated_at = NOW()
            WHERE id IN (
                SELECT id
                FROM ranked_tokens
                WHERE row_number > 1
            )
        SQL);

        DB::transaction(function (): void {
            $usersRequiringUnavailableStatus = DB::select(<<<'SQL'
                SELECT users.id, users.name, users.email
                FROM users
                LEFT JOIN LATERAL (
                    SELECT availability_statuses.is_available
                    FROM availability_statuses
                    WHERE availability_statuses.user_id = users.id
                    ORDER BY
                        availability_statuses.effective_at DESC,
                        availability_statuses.created_at DESC,
                        availability_statuses.id DESC
                    LIMIT 1
                ) AS latest_status ON TRUE
                WHERE NOT EXISTS (
                      SELECT 1
                      FROM fcm_tokens
                      WHERE fcm_tokens.user_id = users.id
                        AND fcm_tokens.client_type = 'operator'
                        AND fcm_tokens.is_active = true
                  )
                  AND (
                      latest_status.is_available IS NULL
                      OR latest_status.is_available = true
                  )
                ORDER BY users.id
                FOR UPDATE OF users
            SQL);

            DB::statement(<<<'SQL'
                UPDATE users
                SET push_enabled = false,
                    updated_at = NOW()
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM fcm_tokens
                    WHERE fcm_tokens.user_id = users.id
                      AND fcm_tokens.client_type = 'operator'
                      AND fcm_tokens.is_active = true
                )
            SQL);

            if ($usersRequiringUnavailableStatus !== []) {
                $timestamp = now();
                DB::table('availability_statuses')->insert(array_map(
                    static fn (object $user): array => [
                        'id' => (string) Str::ulid(),
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'user_email' => $user->email,
                        'status' => 'unavailable',
                        'is_available' => false,
                        'is_system_applied' => true,
                        'changed_by' => null,
                        'changed_by_name' => null,
                        'changed_by_email' => null,
                        'reason' => 'Push notifications disabled.',
                        'effective_at' => $timestamp,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    $usersRequiringUnavailableStatus,
                ));
            }
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS fcm_tokens_active_provider_unique
            ON fcm_tokens (platform, token_hash)
            WHERE is_active = true
              AND token_hash IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fcm_tokens_active_provider_unique');
    }
};
