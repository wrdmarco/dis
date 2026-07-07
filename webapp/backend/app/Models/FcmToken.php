<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\SystemSetting;

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
    ];

    protected $appends = ['is_online'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function getIsOnlineAttribute(): bool
    {
        return (bool) $this->is_active
            && $this->client_type === 'operator'
            && $this->last_seen_at !== null
            && $this->last_seen_at->greaterThan(now()->subMinutes(self::onlineThresholdMinutes()));
    }

    public static function onlineThresholdMinutes(): int
    {
        return max(15, SystemSetting::integer('devices.heartbeat_interval_minutes', 15)) * 2;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
