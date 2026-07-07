<?php

namespace App\Services;

use App\Models\PersonalAccessToken;
use App\Models\User;

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
            'old_mobile_tokens_revoked' => $this->revokeSafeOldMobileTokens($user, $clientType),
        ];
    }

    public function revokeSafeOldMobileTokens(User $user, string $clientType): int
    {
        $ability = $this->clientAbility($clientType);
        if ($ability === null || $this->hasUnlinkedActiveDevice($user, $clientType)) {
            return 0;
        }

        $activeLinkedTokenIds = $this->activeLinkedTokenIds($user, $clientType);
        $tokenIds = $user->tokens()
            ->get()
            ->filter(fn (PersonalAccessToken $token): bool => $this->tokenHasAbility($token, $ability)
                && ! in_array((string) $token->id, $activeLinkedTokenIds, true))
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($tokenIds === []) {
            return 0;
        }

        return $user->tokens()->whereIn('id', $tokenIds)->delete();
    }

    private function clientAbility(string $clientType): ?string
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
}
