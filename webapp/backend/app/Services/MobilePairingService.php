<?php

namespace App\Services;

use App\Models\MobilePairingCode;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ApiDateTime;
use App\Support\MobileApiPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MobilePairingService
{
    private const TTL_SECONDS = 15;

    public function __construct(private readonly AuditService $auditService) {}

    public function canUseClient(User $user, string $clientType): bool
    {
        return match ($this->clientCategory($clientType)) {
            'operator' => $user->canUseOperatorApp(),
            'admin' => $user->canUseAdminApp(),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function create(User $user, string $clientType, Request $request): array
    {
        $pairingType = $this->clientCategory($clientType);
        if ($pairingType === null) {
            throw ValidationException::withMessages([
                'client_type' => ['Onbekend app-type.'],
            ]);
        }

        $code = $this->generateManualCode();
        $normalizedCode = $this->normalizeCode($code);
        $serverUrl = $this->serverRootUrl();
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        MobilePairingCode::query()
            ->where('expires_at', '<', now()->subMinute())
            ->delete();

        $pairing = MobilePairingCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => $this->codeHash($normalizedCode),
            'client_type' => $pairingType,
            'expires_at' => $expiresAt,
        ]);

        $payload = [
            'id' => $pairing->id,
            'server_url' => $serverUrl,
            'api_base_url' => $this->apiBaseUrl($serverUrl),
            'client_type' => $pairingType,
            'code' => $code,
            'expires_at' => ApiDateTime::dateTime($expiresAt),
            'ttl_seconds' => self::TTL_SECONDS,
        ];
        $payload['deeplink_url'] = $this->deeplinkUrl($payload);
        $payload['qr_payload'] = $payload['deeplink_url'];

        $this->auditService->record('auth.mobile_pairing_created', $pairing, $user, [
            'client_type' => $pairingType,
            'expires_at' => $payload['expires_at'],
        ], null, $request);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function consume(string $code, string $clientType, string $deviceName, Request $request): array
    {
        return DB::transaction(function () use ($code, $clientType, $deviceName, $request): array {
            $pairing = MobilePairingCode::query()
                ->with(['user.roles.permissions', 'user.teams'])
                ->where('code_hash', $this->codeHash($this->normalizeCode($code)))
                ->lockForUpdate()
                ->first();

            if ($pairing === null || $pairing->consumed_at !== null || $pairing->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'code' => ['Koppelcode is verlopen of al gebruikt. Maak een nieuwe code op de softwarepagina.'],
                ]);
            }

            if (! $this->pairingMatchesClient((string) $pairing->client_type, $clientType)) {
                throw ValidationException::withMessages([
                    'client_type' => ['Deze koppelcode hoort bij een andere app.'],
                ]);
            }

            $user = $pairing->user;
            if ($user === null || $user->account_status !== 'active') {
                throw ValidationException::withMessages([
                    'code' => ['Deze koppelcode kan niet meer worden gebruikt.'],
                ]);
            }

            if (! $this->canUseClient($user, $clientType)) {
                throw ValidationException::withMessages([
                    'client_type' => ['Deze gebruiker heeft geen toegang tot deze mobiele app.'],
                ]);
            }

            $pairing->update([
                'consumed_at' => now(),
                'consumed_ip' => $request->ip(),
                'consumed_user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            ]);

            $user->forceFill([
                'last_login_at' => now(),
                'failed_login_attempts' => 0,
                'login_locked_until' => null,
            ])->save();

            $this->auditService->record('auth.mobile_pairing_consumed', $pairing, $user, [
                'client_type' => $clientType,
                'device_name' => $deviceName,
            ], null, $request);

            return [
                'token' => $user->createToken($deviceName, ['*', $this->clientAbility($clientType)])->plainTextToken,
                'user' => MobileApiPayload::user($user->load(['roles', 'teams'])),
                'client_type' => $clientType,
            ];
        });
    }

    private function generateManualCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($index = 0; $index < 10; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 5).'-'.substr($code, 5);
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function codeHash(string $code): string
    {
        return hash('sha256', $code);
    }

    private function clientAbility(string $clientType): string
    {
        return $this->clientCategory($clientType) === 'admin' ? 'client:admin' : 'client:operator';
    }

    private function pairingMatchesClient(string $pairingType, string $clientType): bool
    {
        $pairingCategory = $this->clientCategory($pairingType);
        $clientCategory = $this->clientCategory($clientType);

        return $pairingCategory !== null && $pairingCategory === $clientCategory;
    }

    private function clientCategory(string $clientType): ?string
    {
        return match ($clientType) {
            'operator', 'operator_android', 'operator_ios' => 'operator',
            'admin', 'admin_android', 'admin_ios' => 'admin',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function deeplinkUrl(array $payload): string
    {
        $scheme = $this->clientCategory((string) $payload['client_type']) === 'admin' ? 'dis-admin' : 'dis';

        return $scheme.'://pair?'.http_build_query([
            'server' => $payload['server_url'],
            'code' => $payload['code'],
            'client_type' => $payload['client_type'],
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function serverRootUrl(): string
    {
        $configured = SystemSetting::string('mobile.api_base_url') ?: SystemSetting::string('app.public_url');
        if ($configured === null) {
            throw ValidationException::withMessages([
                'server_url' => ['Stel eerst de mobiele API URL of publieke web URL in bij instellingen.'],
            ]);
        }

        $root = $configured;
        $root = rtrim($root, '/');

        return preg_replace('#/api$#i', '', $root) ?? $root;
    }

    private function apiBaseUrl(string $serverUrl): string
    {
        return rtrim($serverUrl, '/').'/api';
    }
}
