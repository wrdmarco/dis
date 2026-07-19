<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WallboardSession extends Model
{
    use UsesUlids;

    protected $fillable = [
        'wallboard_id',
        'token_hash',
        'previous_token_hash',
        'previous_token_expires_at',
        'device_name',
        'ip_address',
        'user_agent',
        'last_seen_at',
        'last_rotated_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
        'previous_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'previous_token_expires_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_rotated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function wallboard(): BelongsTo
    {
        return $this->belongsTo(Wallboard::class);
    }
}
