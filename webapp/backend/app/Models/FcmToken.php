<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FcmToken extends Model
{
    use UsesUlids;

    protected $fillable = [
        'user_id',
        'device_id',
        'device_manufacturer',
        'device_model',
        'android_version',
        'sdk_version',
        'token',
        'token_hash',
        'platform',
        'app_version',
        'is_active',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
