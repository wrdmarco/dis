<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use App\Support\ApiDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FcmToken extends Model
{
    use UsesUlids;

    protected $fillable = [
        'user_id',
        'device_id',
        'device_type',
        'device_name',
        'device_manufacturer',
        'device_model',
        'android_version',
        'sdk_version',
        'token',
        'token_hash',
        'personal_access_token_id',
        'platform',
        'client_type',
        'app_version',
        'is_active',
        'last_seen_at',
        'revoked_at',
        'revocation_generation',
    ];

    protected $hidden = ['personalAccessToken'];

    protected $appends = ['is_online'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function getIsOnlineAttribute(): bool
    {
        return (bool) $this->is_active
            && $this->client_type === 'operator'
            && $this->seenAfterMinutes(self::onlineThresholdMinutes());
    }

    public function getIsReachableAttribute(): bool
    {
        return $this->isReachableFor();
    }

    public function isReachableFor(
        ?User $user = null,
        ?PersonalAccessToken $accessToken = null,
    ): bool {
        if (! (bool) $this->is_active
            || $this->client_type !== 'operator'
            || ! $this->seenAfterMinutes(self::pushReachabilityThresholdMinutes())) {
            return false;
        }

        $user ??= $this->relationLoaded('user')
            ? $this->getRelation('user')
            : $this->user()->first();
        if (! $user instanceof User
            || (string) $user->getKey() !== (string) $this->user_id
            || $user->account_status !== 'active'
            || ! (bool) $user->push_enabled) {
            return false;
        }

        $accessToken ??= $this->relationLoaded('personalAccessToken')
            ? $this->getRelation('personalAccessToken')
            : $this->personalAccessToken()->first();

        return $accessToken instanceof PersonalAccessToken
            && (string) $accessToken->getKey() === (string) $this->personal_access_token_id
            && $accessToken->tokenable_type === User::class
            && (string) $accessToken->tokenable_id === (string) $this->user_id
            && in_array('client:operator', $accessToken->abilities ?? [], true)
            && $accessToken->expires_at?->lessThanOrEqualTo(now()) !== true;
    }

    public static function onlineThresholdMinutes(): int
    {
        return max(15, SystemSetting::integer('devices.heartbeat_interval_minutes', 15)) * 2;
    }

    /**
     * Android may defer normal-priority heartbeat traffic while a device is in
     * Doze. Operational push selection therefore needs a wider grace period
     * than the strict online indicator; the actual alarm remains HIGH priority.
     */
    public static function pushReachabilityThresholdMinutes(): int
    {
        $heartbeatInterval = max(15, SystemSetting::integer('devices.heartbeat_interval_minutes', 15));

        return max(24 * 60, $heartbeatInterval * 8);
    }

    /**
     * @param  Builder<FcmToken>  $query
     * @return Builder<FcmToken>
     */
    public function scopeReachable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('client_type', 'operator')
            ->where('last_seen_at', '>', now()->subMinutes(self::pushReachabilityThresholdMinutes()))
            ->whereHas('user', fn (Builder $users) => $users
                ->where('account_status', 'active')
                ->where('push_enabled', true))
            ->whereHas('personalAccessToken', fn (Builder $tokens) => $tokens
                ->where('tokenable_type', User::class)
                ->whereColumn('personal_access_tokens.tokenable_id', 'fcm_tokens.user_id')
                ->whereJsonContains('abilities', 'client:operator')
                ->where(fn (Builder $expiry) => $expiry
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now())));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    private function seenAfterMinutes(int $minutes): bool
    {
        $lastSeenAt = $this->last_seen_at !== null
            ? ApiDateTime::comparableWallClock($this->last_seen_at)
            : null;
        $cutoff = ApiDateTime::comparableWallClock(now())->subMinutes($minutes);

        return $lastSeenAt !== null && $lastSeenAt->greaterThan($cutoff);
    }
}
