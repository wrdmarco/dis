<?php

namespace App\Services;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;

final class MobileDeviceSessionService
{
    /**
     * @return array{linked_access_token_revoked: bool, old_mobile_tokens_revoked: int}
     */
    public function revokeLinkedAndSafeOldTokens(User $user, ?string $linkedAccessTokenId, string $clientType): array
    {
        $linkedRevoked = false;
        if (is_string($linkedAccessTokenId) && $linkedAccessTokenId !== '') {
            $linkedRevoked = $user->tokens()->whereKey($linkedAccessTokenId)->delete() > 0;
        }

        return [
            'linked_access_token_revoked' => $linkedRevoked,
            // A second, newly authenticated mobile session may not have
            // registered its provider token yet. Per-device revocation must
            // therefore delete only the session explicitly bound to that
            // device.
            'old_mobile_tokens_revoked' => 0,
        ];
    }

    public function revokeSafeOldMobileTokens(
        User $user,
        string $clientType,
        ?PersonalAccessToken $currentAccessToken = null,
    ): int {
        $ability = $this->clientAbility($clientType);
        if ($ability === null
            || $currentAccessToken === null
            || $this->hasUnlinkedActiveDevice($user, $clientType)) {
            return 0;
        }

        $activeLinkedTokenIds = $this->activeLinkedTokenIds($user, $clientType);
        $tokenIds = $user->tokens()
            ->get()
            ->filter(fn (PersonalAccessToken $token): bool => $this->tokenHasAbility($token, $ability)
                && ! in_array((string) $token->id, $activeLinkedTokenIds, true))
            ->filter(fn (PersonalAccessToken $token): bool => $this->isStrictlyOlder(
                $token,
                $currentAccessToken,
            ))
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($tokenIds === []) {
            return 0;
        }

        return $user->tokens()->whereIn('id', $tokenIds)->delete();
    }

    public function liveTokenForClient(
        User $user,
        ?string $accessTokenId,
        string $clientType,
        bool $lockForUpdate = false,
    ): ?PersonalAccessToken {
        $ability = $this->clientAbility($clientType);
        $accessTokenId = trim((string) $accessTokenId);
        if ($ability === null || $accessTokenId === '') {
            return null;
        }

        $query = $user->tokens()->whereKey($accessTokenId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $token = $query->first();
        if (! $token instanceof PersonalAccessToken
            || ! $this->tokenHasAbility($token, $ability)
            || $token->expires_at?->lessThanOrEqualTo(now()) === true) {
            return null;
        }

        return $token;
    }

    /**
     * @throws AuthenticationException
     */
    public function requireLiveTokenForClient(
        User $user,
        ?string $accessTokenId,
        string $clientType,
        bool $lockForUpdate = false,
    ): PersonalAccessToken {
        return $this->liveTokenForClient(
            $user,
            $accessTokenId,
            $clientType,
            $lockForUpdate,
        ) ?? throw new AuthenticationException('Unauthenticated.');
    }

    public function clientAbility(string $clientType): ?string
    {
        return match ($clientType) {
            'operator' => 'client:operator',
            'admin' => 'client:admin',
            default => null,
        };
    }

    private function hasUnlinkedActiveDevice(User $user, string $clientType): bool
    {
        return $user->fcmTokens()
            ->where('client_type', $clientType)
            ->where('is_active', true)
            ->whereNull('personal_access_token_id')
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function activeLinkedTokenIds(User $user, string $clientType): array
    {
        return $user->fcmTokens()
            ->where('client_type', $clientType)
            ->where('is_active', true)
            ->whereNotNull('personal_access_token_id')
            ->pluck('personal_access_token_id')
            ->filter(fn ($id): bool => is_string($id) && $id !== '')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    private function tokenHasAbility(PersonalAccessToken $token, string $ability): bool
    {
        $abilities = is_array($token->abilities ?? null) ? $token->abilities : [];

        return in_array($ability, $abilities, true);
    }

    private function isStrictlyOlder(
        PersonalAccessToken $candidate,
        PersonalAccessToken $current,
    ): bool {
        if ($candidate->created_at === null || $current->created_at === null) {
            return false;
        }

        if ($candidate->created_at->lessThan($current->created_at)) {
            return true;
        }

        return $candidate->created_at->equalTo($current->created_at)
            && strcmp((string) $candidate->id, (string) $current->id) < 0;
    }
}
