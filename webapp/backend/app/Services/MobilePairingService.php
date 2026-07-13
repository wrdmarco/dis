<?php

namespace App\Services;

use App\Models\MobilePairingCode;
use App\Models\PersonalAccessToken;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ApiDateTime;
use App\Support\MobileApiPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MobilePairingService
{
    private const TTL_SECONDS = 30;
    private const STORE_REVIEW_PAIRING_TTL_SECONDS = 21600;
    private const STORE_REVIEW_TOKEN_TTL_HOURS = 24;
    private const MOBILE_TOKEN_TTL_DAYS = 180;
    private const STORE_REVIEW_MODE = 'store_android';
    private const STORE_REVIEW_EMAIL = 'google-play-review@system.dis.local';

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
    public function createStoreReviewAndroid(User $actor, Request $request): array
    {
        $user = $this->storeReviewUser();
        $code = $this->generateManualCode();
        $normalizedCode = $this->normalizeCode($code);
        $serverUrl = $this->serverRootUrl();
        $expiresAt = now()->addSeconds(self::STORE_REVIEW_PAIRING_TTL_SECONDS);

        MobilePairingCode::query()
            ->where('expires_at', '<', now()->subMinute())
            ->delete();

        $pairing = MobilePairingCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => $this->codeHash($normalizedCode),
            'client_type' => 'operator_android',
            'review_mode' => self::STORE_REVIEW_MODE,
            'expires_at' => $expiresAt,
        ]);

        $payload = [
            'id' => $pairing->id,
            'server_url' => $serverUrl,
            'api_base_url' => $this->apiBaseUrl($serverUrl),
            'client_type' => 'operator_android',
            'code' => $code,
            'expires_at' => ApiDateTime::dateTime($expiresAt),
            'ttl_seconds' => self::STORE_REVIEW_PAIRING_TTL_SECONDS,
        ];
        $payload['deeplink_url'] = $this->deeplinkUrl($payload);
        $payload['qr_payload'] = $payload['deeplink_url'];

        $this->auditService->record('auth.store_review_pairing_created', $pairing, $actor, [
            'client_type' => 'operator_android',
            'expires_at' => $payload['expires_at'],
        ], null, $request);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function storeReviewStatus(): array
    {
        $user = $this->findStoreReviewUser();
        $lastPairing = MobilePairingCode::query()
            ->where('review_mode', self::STORE_REVIEW_MODE)
            ->latest('created_at')
            ->first();
        $lastConsumedPairing = MobilePairingCode::query()
            ->where('review_mode', self::STORE_REVIEW_MODE)
            ->whereNotNull('consumed_at')
            ->latest('consumed_at')
            ->first();
        $latestToken = $user === null ? null : $this->latestStoreReviewToken($user);

        return [
            'configured' => $user !== null,
            'account_name' => $user?->name,
            'last_login_at' => ApiDateTime::dateTime($user?->last_login_at),
            'last_pairing_created_at' => ApiDateTime::dateTime($lastPairing?->created_at),
            'last_pairing_expires_at' => ApiDateTime::dateTime($lastPairing?->expires_at),
            'last_pairing_consumed_at' => ApiDateTime::dateTime($lastConsumedPairing?->consumed_at),
            'last_pairing_ip' => $lastConsumedPairing?->consumed_ip,
            'last_pairing_user_agent' => $lastConsumedPairing?->consumed_user_agent,
            'pairing_was_used' => $lastConsumedPairing !== null,
            'token_exists' => $latestToken !== null,
            'token_is_active' => $latestToken !== null && $latestToken->expires_at !== null && $latestToken->expires_at->isFuture(),
            'token_last_used_at' => ApiDateTime::dateTime($latestToken?->last_used_at),
            'token_expires_at' => ApiDateTime::dateTime($latestToken?->expires_at),
            'token_created_at' => ApiDateTime::dateTime($latestToken?->created_at),
        ];
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

            if ($pairing === null || $pairing->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'code' => ['Koppelcode is verlopen of al gebruikt. Maak een nieuwe code op de softwarepagina.'],
                ]);
            }

            $isStoreReviewPairing = (string) ($pairing->review_mode ?? '') === self::STORE_REVIEW_MODE;
            if (! $isStoreReviewPairing && $pairing->consumed_at !== null) {
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
            if ($isStoreReviewPairing) {
                return $this->consumeStoreReviewPairing($pairing, $user, $clientType, $deviceName, $request);
            }

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
                'token' => $user->createToken($deviceName, ['*', $this->clientAbility($clientType)], now()->addDays(self::MOBILE_TOKEN_TTL_DAYS))->plainTextToken,
                'user' => MobileApiPayload::user($user->load(['roles', 'teams'])),
                'client_type' => $clientType,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function consumeStoreReviewPairing(MobilePairingCode $pairing, ?User $user, string $clientType, string $deviceName, Request $request): array
    {
        if ($clientType !== 'operator_android') {
            throw ValidationException::withMessages([
                'client_type' => ['Deze koppelcode hoort bij de Android operator-app.'],
            ]);
        }

        if ($user === null || ! $user->isStoreReviewAccount()) {
            throw ValidationException::withMessages([
                'code' => ['Deze koppelcode kan niet meer worden gebruikt.'],
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
            'push_enabled' => true,
        ])->save();

        $tokenExpiresAt = now()->addHours(self::STORE_REVIEW_TOKEN_TTL_HOURS);

        $this->auditService->record('auth.store_review_pairing_consumed', $pairing, $user, [
            'client_type' => $clientType,
            'device_name' => $deviceName,
            'token_expires_at' => ApiDateTime::dateTime($tokenExpiresAt),
        ], null, $request);

        return [
            'token' => $user->createToken(
                $deviceName,
                ['client:store_review'],
                $tokenExpiresAt,
            )->plainTextToken,
            'user' => MobileApiPayload::user($user->load(['roles', 'teams'])),
            'client_type' => $clientType,
        ];
    }

    private function storeReviewUser(): User
    {
        $attributes = [
            'name' => 'Google Play Review',
            'first_name' => 'Google Play',
            'last_name' => 'Review',
            'phone_number' => '+31000000000',
            'home_city' => 'Utrecht',
            'home_region' => 'Utrecht',
            'home_country' => 'NL',
            'account_status' => 'store_review',
            'push_enabled' => true,
            'max_operator_devices' => 0,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
            'mail_preferences' => [],
        ];

        /** @var User $user */
        $user = $this->findStoreReviewUser();
        if ($user === null) {
            $user = User::query()->create($attributes + [
                'email' => self::STORE_REVIEW_EMAIL,
                'password' => Hash::make(Str::uuid()->toString()),
            ]);
        } else {
            if (method_exists($user, 'restore') && $user->trashed()) {
                $user->restore();
            }
            $user->forceFill($attributes)->save();
        }

        $user->roles()->sync([]);
        $user->teams()->sync([]);

        return $user;
    }

    private function findStoreReviewUser(): ?User
    {
        /** @var User|null $user */
        $user = User::withTrashed()->where('email', self::STORE_REVIEW_EMAIL)->first();

        return $user;
    }

    private function latestStoreReviewToken(User $user): ?PersonalAccessToken
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->latest('created_at')
            ->get()
            ->first(function (PersonalAccessToken $token): bool {
                $abilities = is_array($token->abilities ?? null) ? $token->abilities : [];

                return in_array('client:store_review', $abilities, true);
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
