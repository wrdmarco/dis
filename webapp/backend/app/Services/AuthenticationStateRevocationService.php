<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuthenticationStateRevocationService
{
    /**
     * @return array{tokens: int, sessions: int, wallboard_sessions: int, wallboard_pairing_requests: int, password_reset_tokens: int, pairing_codes: int, developer_keys: int, push_tokens: int, users: int}
     */
    public function revokeAll(): array
    {
        return DB::transaction(function (): array {
            $revokedAt = now();
            $tokens = DB::table('personal_access_tokens')->delete();
            $sessions = DB::table('sessions')->delete();
            $wallboardPairingRequests = DB::table('wallboard_pairing_requests')->delete();
            $wallboardSessions = DB::table('wallboard_sessions')->delete();
            $passwordResetTokens = DB::table('password_reset_tokens')->delete();
            $pairingCodes = DB::table('mobile_pairing_codes')->delete();
            $developerKeys = DB::table('system_settings')
                ->where('key', 'developer.android_upload')
                ->delete();
            $pushTokens = DB::table('fcm_tokens')
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'revoked_at' => $revokedAt,
                    'revocation_generation' => null,
                    'updated_at' => $revokedAt,
                ]);
            $users = DB::table('users')->update([
                'push_enabled' => false,
                'auth_session_version' => DB::raw('auth_session_version + 1'),
                'updated_at' => $revokedAt,
            ]);
            $availableUsers = DB::select(<<<'SQL'
                SELECT users.id, users.name, users.email
                FROM users
                WHERE (
                    SELECT availability_statuses.is_available
                    FROM availability_statuses
                    WHERE availability_statuses.user_id = users.id
                    ORDER BY availability_statuses.effective_at DESC,
                             availability_statuses.created_at DESC,
                             availability_statuses.id DESC
                    LIMIT 1
                ) = true
            SQL);
            if ($availableUsers !== []) {
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
                        'effective_at' => $revokedAt,
                        'created_at' => $revokedAt,
                        'updated_at' => $revokedAt,
                    ],
                    $availableUsers,
                ));
            }

            return [
                'tokens' => $tokens,
                'sessions' => $sessions,
                'wallboard_sessions' => $wallboardSessions,
                'wallboard_pairing_requests' => $wallboardPairingRequests,
                'password_reset_tokens' => $passwordResetTokens,
                'pairing_codes' => $pairingCodes,
                'developer_keys' => $developerKeys,
                'push_tokens' => $pushTokens,
                'users' => $users,
            ];
        }, 3);
    }
}
