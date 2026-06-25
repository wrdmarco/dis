<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\User;

final class DeviceService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly StatusService $statusService,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function registerFcmToken(User $user, array $data): FcmToken
    {
        $token = FcmToken::query()->updateOrCreate(
            ['user_id' => $user->id, 'device_id' => $data['device_id']],
            [
                'token' => $data['token'],
                'platform' => $data['platform'] ?? 'android',
                'app_version' => $data['app_version'] ?? null,
                'is_active' => true,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );

        $user->update(['push_enabled' => true]);
        $this->auditService->record('push.token_registered', $token, $user);

        return $token;
    }

    public function revokeFcmToken(User $user, FcmToken $token): void
    {
        abort_unless($token->user_id === $user->id, 403);
        $token->update(['is_active' => false, 'revoked_at' => now()]);

        if (! $user->fcmTokens()->where('is_active', true)->exists()) {
            $user->update(['push_enabled' => false]);
            $this->statusService->enforcePushUnavailable($user);
        }

        $this->auditService->record('push.token_revoked', $token, $user);
    }
}

