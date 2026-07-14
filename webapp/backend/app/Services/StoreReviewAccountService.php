<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\ApiDateTime;
use App\Support\MobileApiPayload;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class StoreReviewAccountService
{
    private const TOKEN_TTL_HOURS = 24;

    /** @var array<string, array{name: string, email: string, client_type: string}> */
    private const ACCOUNTS = [
        'apple' => [
            'name' => 'Apple App Review',
            'email' => 'apple-app-review@system.dis.local',
            'client_type' => 'operator_ios',
        ],
        'google' => [
            'name' => 'Google Play Review',
            'email' => 'google-play-review@system.dis.local',
            'client_type' => 'operator_android',
        ],
    ];

    public function __construct(private readonly AuditService $auditService) {}

    /** @return array{accounts: list<array<string, mixed>>} */
    public function status(): array
    {
        return [
            'accounts' => collect(array_keys(self::ACCOUNTS))
                ->map(fn (string $platform): array => $this->accountStatus($platform))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function configure(string $platform, bool $enabled, ?string $password, User $actor, Request $request): array
    {
        $definition = $this->definition($platform);
        $user = $this->find($platform);

        if ($enabled && ($password === null || $password === '')) {
            throw ValidationException::withMessages([
                'password' => ['Stel bij het activeren eerst een wachtwoord van minimaal 12 tekens in.'],
            ]);
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => $definition['name'],
                'first_name' => $platform === 'apple' ? 'Apple' : 'Google Play',
                'last_name' => 'Review',
                'email' => $definition['email'],
                'password' => $password ?? bin2hex(random_bytes(32)),
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
            ]);
        } else {
            if ($enabled && $user->trashed()) {
                $user->restore();
            }
            $attributes = [
                'name' => $definition['name'],
                'account_status' => 'store_review',
                'push_enabled' => true,
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ];
            if ($password !== null && $password !== '') {
                $attributes['password'] = $password;
            }
            $user->forceFill($attributes)->save();
        }

        $user->roles()->sync([]);
        $user->teams()->sync([]);

        if ($password !== null && $password !== '') {
            $this->revokeReviewTokens($user);
        }

        if (! $enabled) {
            $this->revokeReviewTokens($user);
            if (! $user->trashed()) {
                $user->delete();
            }
        }

        $this->auditService->record('auth.store_review_account_updated', $user, $actor, [
            'platform' => $platform,
            'enabled' => $enabled,
            'password_changed' => $password !== null && $password !== '',
        ], null, $request);

        return $this->accountStatus($platform);
    }

    public function canAuthenticate(User $user, string $clientType): bool
    {
        if (! $user->isStoreReviewAccount() || $clientType === 'web') {
            return false;
        }

        foreach (self::ACCOUNTS as $definition) {
            if ($user->email === $definition['email']) {
                return $clientType === $definition['client_type'];
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function authenticate(User $user, string $clientType, string $deviceName, Request $request): array
    {
        $expiresAt = now()->addHours(self::TOKEN_TTL_HOURS);
        $user->forceFill([
            'last_login_at' => now(),
            'failed_login_attempts' => 0,
            'login_locked_until' => null,
            'push_enabled' => true,
        ])->save();

        $this->auditService->record('auth.store_review_login', $user, $user, [
            'client_type' => $clientType,
            'device_name' => $deviceName,
            'token_expires_at' => ApiDateTime::dateTime($expiresAt),
        ], null, $request);

        return [
            'requires_2fa' => false,
            'token' => $user->createToken($deviceName, ['client:store_review'], $expiresAt)->plainTextToken,
            'user' => MobileApiPayload::user($user->load(['roles', 'teams'])),
        ];
    }

    /** @return array<string, mixed> */
    private function accountStatus(string $platform): array
    {
        $definition = $this->definition($platform);
        $user = $this->find($platform);
        $latestToken = $user === null ? null : $this->latestReviewToken($user);

        return [
            'platform' => $platform,
            'client_type' => $definition['client_type'],
            'name' => $definition['name'],
            'username' => $definition['email'],
            'configured' => $user !== null,
            'enabled' => $user !== null && ! $user->trashed(),
            'last_login_at' => ApiDateTime::dateTime($user?->last_login_at),
            'token_is_active' => $latestToken?->expires_at?->isFuture() === true,
            'token_last_used_at' => ApiDateTime::dateTime($latestToken?->last_used_at),
            'token_expires_at' => ApiDateTime::dateTime($latestToken?->expires_at),
            'recent_login_events' => $user === null ? [] : $this->recentLoginEvents($user),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentLoginEvents(User $user): array
    {
        return AuditLog::query()
            ->where('target_type', User::class)
            ->where('target_id', $user->id)
            ->whereIn('action', ['auth.store_review_login', 'auth.store_review_login_blocked'])
            ->latest('created_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(static function (AuditLog $log): array {
                $metadata = (array) $log->metadata;

                return [
                    'id' => $log->id,
                    'result' => $log->action === 'auth.store_review_login' ? 'success' : 'blocked',
                    'client_type' => isset($metadata['client_type']) ? (string) $metadata['client_type'] : null,
                    'device_name' => isset($metadata['device_name']) ? (string) $metadata['device_name'] : null,
                    'ip_address' => $log->ip_address,
                    'created_at' => ApiDateTime::dateTime($log->created_at),
                ];
            })
            ->all();
    }

    /** @return array{name: string, email: string, client_type: string} */
    private function definition(string $platform): array
    {
        if (! isset(self::ACCOUNTS[$platform])) {
            throw ValidationException::withMessages(['platform' => ['Onbekend reviewplatform.']]);
        }

        return self::ACCOUNTS[$platform];
    }

    private function find(string $platform): ?User
    {
        return User::withTrashed()->where('email', $this->definition($platform)['email'])->first();
    }

    private function latestReviewToken(User $user): ?PersonalAccessToken
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->latest('created_at')
            ->get()
            ->first(fn (PersonalAccessToken $token): bool => in_array('client:store_review', (array) $token->abilities, true));
    }

    private function revokeReviewTokens(User $user): void
    {
        PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->get()
            ->filter(fn (PersonalAccessToken $token): bool => in_array('client:store_review', (array) $token->abilities, true))
            ->each->delete();
    }
}
