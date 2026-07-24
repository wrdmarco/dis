<?php

namespace App\Services;

use App\Exceptions\TransientPushDeliveryException;
use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PushNotificationService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly StatusService $statusService,
        private readonly RevokedDevicePushQueue $revokedDevicePush,
        private readonly MobileDeviceSessionService $mobileSessions,
        private readonly FcmTokenIdentityLock $tokenIdentityLock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{queued_tokens: int, recipient_users: int}
     */
    public function sendManual(User $actor, array $data): array
    {
        /** @var Collection<int, User> $users */
        $users = User::query()
            ->with(['fcmTokens' => fn ($tokens) => $this->onlineOperatorTokenQuery($tokens)])
            ->where('account_status', 'active')
            ->where('push_enabled', true)
            ->whereHas('fcmTokens', fn ($tokens) => $this->onlineOperatorTokenQuery($tokens))
            ->where(function (Builder $query) use ($data): void {
                $teamIds = $this->expandTeamIds($data['team_ids'] ?? []);
                $roleIds = $data['role_ids'] ?? [];
                $userIds = $data['user_ids'] ?? [];

                if ($teamIds !== []) {
                    $query->orWhereHas('teams', fn ($teams) => $teams->whereIn('teams.id', $teamIds));
                }

                if ($roleIds !== []) {
                    $query->orWhereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $roleIds));
                }

                if ($userIds !== []) {
                    $query->orWhereIn('id', $userIds);
                }
            })
            ->get();

        $queuedTokens = 0;
        foreach ($users as $user) {
            foreach ($user->fcmTokens as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'manual_admin',
                    (string) $data['title'],
                    (string) $data['body'],
                    [
                        'type' => 'manual_admin',
                        'sent_by' => (string) $actor->id,
                    ],
                )->onQueue('push');
                $queuedTokens++;
            }
        }

        $this->auditService->record('push.manual_sent', User::class, $actor, [
            'team_ids' => $data['team_ids'] ?? [],
            'expanded_team_ids' => $this->expandTeamIds($data['team_ids'] ?? []),
            'role_ids' => $data['role_ids'] ?? [],
            'user_ids' => $data['user_ids'] ?? [],
            'recipient_users' => $users->count(),
            'queued_tokens' => $queuedTokens,
        ]);

        return [
            'queued_tokens' => $queuedTokens,
            'recipient_users' => $users->count(),
        ];
    }

    public function revokeToken(FcmToken $token, ?User $actor): void
    {
        $lockedIdentityKey = FcmTokenIdentityLock::keyForToken($token);
        $lockedProviderTokenKey = FcmTokenIdentityLock::providerTokenKeyForToken($token);

        $this->tokenIdentityLock->synchronizedMany(
            [[
                'user_id' => (string) $token->user_id,
                'device_id' => (string) $token->device_id,
                'client_type' => (string) $token->client_type,
            ]],
            function () use ($token, $actor, $lockedIdentityKey, $lockedProviderTokenKey): void {
                $revokedToken = DB::transaction(function () use (
                    $token,
                    $actor,
                    $lockedIdentityKey,
                    $lockedProviderTokenKey,
                ): ?FcmToken {
                    $user = User::query()
                        ->whereKey($token->user_id)
                        ->lockForUpdate()
                        ->first();
                    $token = FcmToken::query()
                        ->whereKey($token->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    if (! hash_equals($lockedIdentityKey, FcmTokenIdentityLock::keyForToken($token))
                        || ! hash_equals(
                            $lockedProviderTokenKey,
                            FcmTokenIdentityLock::providerTokenKeyForToken($token),
                        )) {
                        throw TransientPushDeliveryException::forDeviceIdentityChange();
                    }
                    if (! $token->is_active) {
                        return null;
                    }

                    $linkedAccessTokenId = $token->personal_access_token_id;
                    $token->update([
                        'is_active' => false,
                        'revoked_at' => now(),
                        'revocation_generation' => (string) Str::ulid(),
                    ]);

                    $sessionCleanup = $user !== null
                        ? $this->mobileSessions->revokeLinkedAndSafeOldTokens($user, $linkedAccessTokenId, (string) $token->client_type)
                        : ['linked_access_token_revoked' => false, 'old_mobile_tokens_revoked' => 0];

                    if ($user !== null && ! $user->fcmTokens()
                        ->where('client_type', 'operator')
                        ->where('is_active', true)
                        ->exists()) {
                        $user->update(['push_enabled' => false]);
                        $this->statusService->enforcePushUnavailable($user);
                    }

                    $this->auditService->record('push.token_admin_revoked', $token, $actor, [
                        'user_id' => $token->user_id,
                        'device_id' => $token->device_id,
                        'personal_access_token_revoked' => $sessionCleanup['linked_access_token_revoked'],
                        'old_mobile_tokens_revoked' => $sessionCleanup['old_mobile_tokens_revoked'],
                    ]);

                    return $token->refresh();
                });

                if ($revokedToken === null) {
                    return;
                }

                $this->revokedDevicePush->enqueue(
                    $revokedToken,
                    'Toestel verwijderd',
                    'Dit toestel is losgekoppeld van D.I.S.',
                );
            },
            [[
                'platform' => (string) $token->platform,
                'token_hash' => FcmTokenIdentityLock::tokenHash($token),
            ]],
            [(string) $token->user_id],
        );
    }

    public function activateToken(FcmToken $token, ?User $actor): void
    {
        $lockedIdentityKey = FcmTokenIdentityLock::keyForToken($token);
        $lockedProviderTokenKey = FcmTokenIdentityLock::providerTokenKeyForToken($token);
        $providerToken = [
            'platform' => (string) $token->platform,
            'token_hash' => FcmTokenIdentityLock::tokenHash($token),
        ];

        $this->tokenIdentityLock->synchronizedMany(
            [[
                'user_id' => (string) $token->user_id,
                'device_id' => (string) $token->device_id,
                'client_type' => (string) $token->client_type,
            ]],
            function () use ($token, $actor, $lockedIdentityKey, $lockedProviderTokenKey): void {
                DB::transaction(function () use ($token, $actor, $lockedIdentityKey, $lockedProviderTokenKey): void {
                    $user = User::query()
                        ->whereKey($token->user_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $token = FcmToken::query()
                        ->whereKey($token->id)
                        ->where('user_id', $user->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    if (! hash_equals($lockedIdentityKey, FcmTokenIdentityLock::keyForToken($token))
                        || ! hash_equals(
                            $lockedProviderTokenKey,
                            FcmTokenIdentityLock::providerTokenKeyForToken($token),
                        )) {
                        throw TransientPushDeliveryException::forDeviceIdentityChange();
                    }
                    if ($this->mobileSessions->liveTokenForClient(
                        $user,
                        $token->personal_access_token_id,
                        (string) $token->client_type,
                        true,
                    ) === null) {
                        throw ValidationException::withMessages([
                            'token' => ['De mobiele sessie van dit toestel is niet meer actief. Registreer het toestel opnieuw vanuit de app.'],
                        ]);
                    }
                    if (FcmToken::query()
                        ->where('platform', $token->platform)
                        ->where('token_hash', FcmTokenIdentityLock::tokenHash($token))
                        ->where('is_active', true)
                        ->where('id', '!=', $token->id)
                        ->exists()) {
                        throw ValidationException::withMessages([
                            'token' => ['Deze providertoken is al aan een ander actief toestel gekoppeld. Registreer het toestel opnieuw vanuit de app.'],
                        ]);
                    }
                    if (FcmToken::query()
                        ->where('platform', $token->platform)
                        ->where('token_hash', FcmTokenIdentityLock::tokenHash($token))
                        ->whereNotNull('revocation_generation')
                        ->where('id', '!=', $token->id)
                        ->exists()) {
                        throw ValidationException::withMessages([
                            'token' => ['Deze providertoken heeft nog een lopende sessie-intrekking. Registreer het toestel opnieuw vanuit de app.'],
                        ]);
                    }

                    $token->update([
                        'is_active' => true,
                        'revoked_at' => null,
                        'revocation_generation' => null,
                    ]);
                    if ($token->client_type === 'operator') {
                        $user->update(['push_enabled' => true]);
                    }

                    $this->auditService->record('push.token_admin_activated', $token, $actor, [
                        'user_id' => $token->user_id,
                        'device_id' => $token->device_id,
                    ]);
                });
            },
            [$providerToken],
            [(string) $token->user_id],
        );
    }

    /**
     * @param  array<int, string>  $teamIds
     * @return array<int, string>
     */
    private function expandTeamIds(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $alertTeamIds = Team::query()
            ->whereIn('id', $teamIds)
            ->with('alertTeams:id')
            ->get()
            ->flatMap(fn (Team $team) => $team->alertTeams->pluck('id'))
            ->all();

        return array_values(array_unique([...$teamIds, ...$alertTeamIds]));
    }

    private function onlineOperatorTokenQuery($tokens)
    {
        return $tokens
            ->where('is_active', true)
            ->where('client_type', 'operator')
            ->where('last_seen_at', '>', now()->subMinutes(FcmToken::pushReachabilityThresholdMinutes()));
    }
}
