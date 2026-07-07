<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Firebase\FcmClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeviceService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly StatusService $statusService,
        private readonly FcmClient $fcmClient,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function registerFcmToken(User $user, array $data, ?PersonalAccessToken $accessToken = null): FcmToken
    {
        return DB::transaction(function () use ($user, $data, $accessToken): FcmToken {
            $clientType = (string) ($data['client_type'] ?? 'operator');
            $tokenHash = hash('sha256', (string) $data['token']);
            $token = FcmToken::query()
                ->where('user_id', $user->id)
                ->where(function (Builder $query) use ($tokenHash, $data, $clientType): void {
                    $query->where('token_hash', $tokenHash)
                        ->orWhere(function (Builder $deviceQuery) use ($data, $clientType): void {
                            $deviceQuery
                                ->where('device_id', $data['device_id'])
                                ->where('client_type', $clientType);
                        });
                })
                ->first();

            if ($token === null && $clientType === 'operator') {
                $activeOperatorDevices = $user->fcmTokens()
                    ->where('client_type', 'operator')
                    ->where('is_active', true)
                    ->count();
                $maximum = max(1, (int) ($user->max_operator_devices ?? 1));
                if ($activeOperatorDevices >= $maximum) {
                    throw ValidationException::withMessages([
                        'device' => ["Deze gebruiker mag maximaal {$maximum} operator-device(s) koppelen."],
                    ]);
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
                'personal_access_token_id' => $accessToken?->id,
                'platform' => $data['platform'] ?? 'android',
                'client_type' => $clientType,
                'app_version' => $data['app_version'] ?? null,
                'is_active' => true,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ];

            if ($token === null) {
                $token = FcmToken::query()->create(['user_id' => $user->id] + $payload);
            } else {
                $token->update($payload);
            }

            if ($clientType === 'operator') {
                $user->update(['push_enabled' => true]);
            }

            $this->auditService->record('push.token_registered', $token, $user, [
                'client_type' => $clientType,
                'device_type' => $payload['device_type'],
                'device_name' => $payload['device_name'],
            ]);

            return $token;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function heartbeat(User $user, array $data, ?PersonalAccessToken $accessToken = null): FcmToken
    {
        $token = $user->fcmTokens()
            ->where('device_id', $data['device_id'])
            ->where('client_type', $data['client_type'] ?? 'operator')
            ->where('is_active', true)
            ->first();

        if ($token === null) {
            throw ValidationException::withMessages(['device_id' => ['Device is niet actief geregistreerd.']]);
        }

        $token->update([
            'device_type' => $data['device_type'] ?? $token->device_type,
            'device_name' => $this->deviceName($data, $token->device_name),
            'app_version' => $data['app_version'] ?? $token->app_version,
            'personal_access_token_id' => $accessToken?->id ?? $token->personal_access_token_id,
            'last_seen_at' => now(),
        ]);

        return $token->refresh();
    }

    public function revokeFcmToken(User $user, FcmToken $token): void
    {
        abort_unless($token->user_id === $user->id, 403);
        $this->notifyDeviceSessionRevoked($token);

        DB::transaction(function () use ($user, $token): void {
            $linkedAccessTokenId = $token->personal_access_token_id;

            $token->update(['is_active' => false, 'revoked_at' => now()]);

            if (is_string($linkedAccessTokenId) && $linkedAccessTokenId !== '') {
                $user->tokens()->whereKey($linkedAccessTokenId)->delete();
            }

            if (! $user->fcmTokens()->where('is_active', true)->exists()) {
                $user->update(['push_enabled' => false]);
                $this->statusService->enforcePushUnavailable($user);
            }

            $this->auditService->record('push.token_revoked', $token, $user, [
                'personal_access_token_revoked' => is_string($linkedAccessTokenId) && $linkedAccessTokenId !== '',
            ]);
        });
    }

    /**
     * @param array<string, mixed> $data
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

    private function notifyDeviceSessionRevoked(FcmToken $token): void
    {
        if (! $token->is_active) {
            return;
        }

        try {
            $this->fcmClient->send(
                $token,
                'Toestel verwijderd',
                'Dit toestel is losgekoppeld van D.I.S.',
                ['type' => 'session_revoked'],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
