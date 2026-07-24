<?php

namespace App\Services;

use App\Exceptions\TransientPushDeliveryException;
use App\Models\FcmToken;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DeviceService
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
     */
    public function registerFcmToken(User $user, array $data, ?PersonalAccessToken $accessToken = null): FcmToken
    {
        $clientType = (string) ($data['client_type'] ?? 'operator');
        $platform = strtolower((string) ($data['platform'] ?? 'android'));
        $tokenHash = hash('sha256', (string) $data['token']);
        $requestedIdentity = [
            'user_id' => (string) $user->id,
            'device_id' => (string) $data['device_id'],
            'client_type' => $clientType,
        ];
        $existingToken = FcmToken::query()
            ->where('user_id', $user->id)
            ->where('device_id', $data['device_id'])
            ->where('client_type', $clientType)
            ->first();
        $providerOwners = FcmToken::query()
            ->where('platform', $platform)
            ->where('token_hash', $tokenHash)
            ->get();
        $scopeTokens = collect([$existingToken])
            ->filter()
            ->merge($providerOwners)
            ->unique(fn (FcmToken $token): string => (string) $token->id)
            ->values();
        $identities = collect([$requestedIdentity])
            ->merge($scopeTokens->map(fn (FcmToken $token): array => [
                'user_id' => (string) $token->user_id,
                'device_id' => (string) $token->device_id,
                'client_type' => (string) $token->client_type,
            ]))
            ->unique(fn (array $identity): string => FcmTokenIdentityLock::key(
                $identity['user_id'],
                $identity['device_id'],
                $identity['client_type'],
            ))
            ->values()
            ->all();
        $providerTokens = collect([[
            'platform' => $platform,
            'token_hash' => $tokenHash,
        ]])
            ->merge($scopeTokens->map(fn (FcmToken $token): array => [
                'platform' => (string) $token->platform,
                'token_hash' => FcmTokenIdentityLock::tokenHash($token),
            ]))
            ->unique(fn (array $providerToken): string => FcmTokenIdentityLock::providerTokenKey(
                $providerToken['platform'],
                $providerToken['token_hash'],
            ))
            ->values()
            ->all();
        $lockedIdentityKeys = array_map(
            static fn (array $identity): string => FcmTokenIdentityLock::key(
                $identity['user_id'],
                $identity['device_id'],
                $identity['client_type'],
            ),
            $identities,
        );
        $lockedProviderTokenKeys = array_map(
            static fn (array $providerToken): string => FcmTokenIdentityLock::providerTokenKey(
                $providerToken['platform'],
                $providerToken['token_hash'],
            ),
            $providerTokens,
        );
        $scopeUserIds = $scopeTokens
            ->pluck('user_id')
            ->push($user->id)
            ->map(fn ($userId): string => (string) $userId)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $this->tokenIdentityLock->synchronizedMany(
            $identities,
            function () use (
                $user,
                $data,
                $accessToken,
                $clientType,
                $platform,
                $tokenHash,
                $lockedIdentityKeys,
                $lockedProviderTokenKeys,
                $scopeUserIds,
            ): FcmToken {
                return DB::transaction(function () use (
                    $user,
                    $data,
                    $accessToken,
                    $clientType,
                    $platform,
                    $tokenHash,
                    $lockedIdentityKeys,
                    $lockedProviderTokenKeys,
                    $scopeUserIds,
                ): FcmToken {
                    $lockedUsers = User::query()
                        ->whereIn('id', $scopeUserIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy(fn (User $lockedUser): string => (string) $lockedUser->id);
                    $user = $lockedUsers->get((string) $user->id) ?? throw new \RuntimeException('Registering user no longer exists.');
                    $liveAccessToken = $this->mobileSessions->requireLiveTokenForClient(
                        $user,
                        $accessToken?->id,
                        $clientType,
                        true,
                    );
                    $token = FcmToken::query()
                        ->where('user_id', $user->id)
                        ->where('device_id', $data['device_id'])
                        ->where('client_type', $clientType)
                        ->lockForUpdate()
                        ->first();
                    $matchingProviderTokens = FcmToken::query()
                        ->where('platform', $platform)
                        ->where('token_hash', $tokenHash)
                        ->orderByDesc('is_active')
                        ->orderByDesc('last_seen_at')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $token ??= $matchingProviderTokens->first(
                        fn (FcmToken $candidate): bool => (string) $candidate->user_id === (string) $user->id
                            && (string) $candidate->client_type === $clientType,
                    );

                    foreach ($matchingProviderTokens as $providerOwner) {
                        if (! in_array(FcmTokenIdentityLock::keyForToken($providerOwner), $lockedIdentityKeys, true)
                            || ! in_array((string) $providerOwner->user_id, $scopeUserIds, true)) {
                            throw TransientPushDeliveryException::forDeviceIdentityChange();
                        }
                    }

                    if ($token !== null
                        && ! in_array(FcmTokenIdentityLock::keyForToken($token), $lockedIdentityKeys, true)) {
                        throw TransientPushDeliveryException::forDeviceIdentityChange();
                    }
                    if ($token !== null
                        && ! in_array(
                            FcmTokenIdentityLock::providerTokenKeyForToken($token),
                            $lockedProviderTokenKeys,
                            true,
                        )) {
                        throw TransientPushDeliveryException::forDeviceIdentityChange();
                    }

                    if ($token === null && $clientType === 'operator') {
                        $activeOperatorDevices = $user->fcmTokens()
                            ->where('client_type', 'operator')
                            ->where('is_active', true)
                            ->distinct()
                            ->count('device_id');
                        $maximum = max(1, (int) ($user->max_operator_devices ?? 1));
                        if ($activeOperatorDevices >= $maximum) {
                            throw ValidationException::withMessages([
                                'device' => ["Deze gebruiker mag maximaal {$maximum} operator-device(s) koppelen."],
                            ]);
                        }
                    }

                    $providerTransfers = 0;
                    $affectedUserIds = [(string) $user->id];
                    foreach ($matchingProviderTokens as $providerOwner) {
                        if ($token !== null && (string) $providerOwner->id === (string) $token->id) {
                            continue;
                        }

                        $wasActive = (bool) $providerOwner->is_active;
                        $providerOwner->forceFill([
                            'is_active' => false,
                            'revoked_at' => $providerOwner->revoked_at ?? now(),
                            // A provider token that is claimed by another
                            // registration may never deliver an older
                            // session-revocation generation.
                            'revocation_generation' => null,
                        ])->save();
                        $providerTransfers += $wasActive ? 1 : 0;
                        $affectedUserIds[] = (string) $providerOwner->user_id;

                        $linkedTokenId = trim((string) $providerOwner->personal_access_token_id);
                        if ($linkedTokenId !== ''
                            && $linkedTokenId !== (string) $liveAccessToken->id) {
                            PersonalAccessToken::query()
                                ->whereKey($linkedTokenId)
                                ->where('tokenable_type', User::class)
                                ->where('tokenable_id', $providerOwner->user_id)
                                ->delete();
                        }
                    }

                    $payload = [
                        'device_id' => $data['device_id'],
                        'device_type' => $data['device_type'] ?? null,
                        'device_name' => $this->deviceName($data),
                        'device_manufacturer' => $data['device_manufacturer'] ?? null,
                        'device_model' => $data['device_model'] ?? null,
                        'android_version' => $data['android_version'] ?? null,
                        'sdk_version' => $data['sdk_version'] ?? null,
                        'token' => $data['token'],
                        'token_hash' => $tokenHash,
                        'personal_access_token_id' => $liveAccessToken->id,
                        'platform' => $platform,
                        'client_type' => $clientType,
                        'app_version' => $data['app_version'] ?? null,
                        'is_active' => true,
                        'last_seen_at' => now(),
                        'revoked_at' => null,
                        'revocation_generation' => null,
                    ];

                    if ($token === null) {
                        $token = FcmToken::query()->create(['user_id' => $user->id] + $payload);
                    } else {
                        $token->update($payload);
                    }

                    if ($clientType === 'operator') {
                        $user->update(['push_enabled' => true]);
                    }

                    $duplicateDevicesRevoked = $this->revokeDuplicateActiveDeviceTokens($token);
                    foreach (array_values(array_unique($affectedUserIds)) as $affectedUserId) {
                        $affectedUser = $lockedUsers->get($affectedUserId);
                        if ($affectedUser !== null
                            && ! $affectedUser->fcmTokens()
                                ->where('client_type', 'operator')
                                ->where('is_active', true)
                                ->exists()) {
                            $affectedUser->update(['push_enabled' => false]);
                            $this->statusService->enforcePushUnavailable($affectedUser);
                        }
                    }

                    $this->auditService->record('push.token_registered', $token, $user, [
                        'client_type' => $clientType,
                        'device_type' => $payload['device_type'],
                        'device_name' => $payload['device_name'],
                        'duplicate_device_tokens_revoked' => $duplicateDevicesRevoked,
                        'provider_token_transfers' => $providerTransfers,
                        'old_mobile_tokens_revoked' => $this->mobileSessions->revokeSafeOldMobileTokens(
                            $user,
                            $clientType,
                            $liveAccessToken,
                        ),
                    ]);

                    return $token;
                });
            },
            $providerTokens,
            $scopeUserIds,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function heartbeat(User $user, array $data, ?PersonalAccessToken $accessToken = null): FcmToken
    {
        $token = $user->fcmTokens()
            ->where('device_id', $data['device_id'])
            ->where('client_type', $data['client_type'] ?? 'operator')
            ->where('is_active', true)
            ->latest('last_seen_at')
            ->first();

        if ($token === null) {
            throw ValidationException::withMessages(['device_id' => ['Device is niet actief geregistreerd.']]);
        }

        $clientType = (string) ($data['client_type'] ?? 'operator');
        $lockedIdentityKey = FcmTokenIdentityLock::keyForToken($token);
        $lockedProviderTokenKey = FcmTokenIdentityLock::providerTokenKeyForToken($token);

        return $this->tokenIdentityLock->synchronizedMany(
            [[
                'user_id' => (string) $token->user_id,
                'device_id' => (string) $token->device_id,
                'client_type' => (string) $token->client_type,
            ]],
            function () use (
                $user,
                $token,
                $data,
                $accessToken,
                $clientType,
                $lockedIdentityKey,
                $lockedProviderTokenKey,
            ): FcmToken {
                return DB::transaction(function () use (
                    $user,
                    $token,
                    $data,
                    $accessToken,
                    $clientType,
                    $lockedIdentityKey,
                    $lockedProviderTokenKey,
                ): FcmToken {
                    $user = User::query()
                        ->whereKey($user->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $liveAccessToken = $this->mobileSessions->requireLiveTokenForClient(
                        $user,
                        $accessToken?->id,
                        $clientType,
                        true,
                    );
                    $token = FcmToken::query()
                        ->whereKey($token->id)
                        ->where('user_id', $user->id)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->first();
                    if ($token === null) {
                        throw ValidationException::withMessages(['device_id' => ['Device is niet actief geregistreerd.']]);
                    }
                    if (! hash_equals($lockedIdentityKey, FcmTokenIdentityLock::keyForToken($token))
                        || ! hash_equals(
                            $lockedProviderTokenKey,
                            FcmTokenIdentityLock::providerTokenKeyForToken($token),
                        )) {
                        throw TransientPushDeliveryException::forDeviceIdentityChange();
                    }

                    $previousAccessTokenId = $token->personal_access_token_id;
                    $token->update([
                        'device_type' => $data['device_type'] ?? $token->device_type,
                        'device_name' => $this->deviceName($data, $token->device_name),
                        'device_manufacturer' => $data['device_manufacturer'] ?? $token->device_manufacturer,
                        'device_model' => $data['device_model'] ?? $token->device_model,
                        'android_version' => $data['android_version'] ?? $token->android_version,
                        'sdk_version' => $data['sdk_version'] ?? $token->sdk_version,
                        'app_version' => $data['app_version'] ?? $token->app_version,
                        'personal_access_token_id' => $liveAccessToken->id,
                        'last_seen_at' => now(),
                    ]);

                    if ((string) $previousAccessTokenId !== (string) $liveAccessToken->id) {
                        $this->mobileSessions->revokeSafeOldMobileTokens(
                            $user,
                            $clientType,
                            $liveAccessToken,
                        );
                    }

                    $this->revokeDuplicateActiveDeviceTokens($token->refresh());

                    return $token->refresh();
                });
            },
            [[
                'platform' => (string) $token->platform,
                'token_hash' => FcmTokenIdentityLock::tokenHash($token),
            ]],
            [(string) $user->id],
        );
    }

    public function revokeFcmToken(User $user, FcmToken $token): void
    {
        abort_unless($token->user_id === $user->id, 403);
        $lockedIdentityKey = FcmTokenIdentityLock::keyForToken($token);
        $lockedProviderTokenKey = FcmTokenIdentityLock::providerTokenKeyForToken($token);

        $this->tokenIdentityLock->synchronizedMany(
            [[
                'user_id' => (string) $token->user_id,
                'device_id' => (string) $token->device_id,
                'client_type' => (string) $token->client_type,
            ]],
            function () use ($user, $token, $lockedIdentityKey, $lockedProviderTokenKey): void {
                $revokedToken = DB::transaction(function () use (
                    $user,
                    $token,
                    $lockedIdentityKey,
                    $lockedProviderTokenKey,
                ): ?FcmToken {
                    $user = User::query()
                        ->whereKey($user->id)
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
                    if (! $token->is_active) {
                        return null;
                    }

                    $linkedAccessTokenId = $token->personal_access_token_id;
                    $token->update([
                        'is_active' => false,
                        'revoked_at' => now(),
                        'revocation_generation' => (string) Str::ulid(),
                    ]);

                    $sessionCleanup = $this->mobileSessions->revokeLinkedAndSafeOldTokens(
                        $user,
                        $linkedAccessTokenId,
                        (string) $token->client_type,
                    );

                    if (! $user->fcmTokens()
                        ->where('client_type', 'operator')
                        ->where('is_active', true)
                        ->exists()) {
                        $user->update(['push_enabled' => false]);
                        $this->statusService->enforcePushUnavailable($user);
                    }

                    $this->auditService->record('push.token_revoked', $token, $user, [
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
            [(string) $user->id],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deviceName(array $data, ?string $fallback = null): string
    {
        $name = trim((string) ($data['device_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $label = trim(implode(' ', array_filter([
            $data['device_manufacturer'] ?? null,
            $data['device_model'] ?? null,
        ], fn ($value): bool => filled($value))));

        return $label !== '' ? $label : ($fallback ?: 'Android device');
    }

    private function revokeDuplicateActiveDeviceTokens(FcmToken $token): int
    {
        return FcmToken::query()
            ->where('user_id', $token->user_id)
            ->where('device_id', $token->device_id)
            ->where('client_type', $token->client_type)
            ->where('is_active', true)
            ->where('id', '!=', $token->id)
            ->update([
                'is_active' => false,
                'revoked_at' => now(),
            ]);
    }
}
