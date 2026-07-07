<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MobilePairingCode extends Model
{
    use UsesUlids;

    protected $fillable = [
        'user_id',
        'code_hash',
        'client_type',
        'expires_at',
        'consumed_at',
        'consumed_ip',
        'consumed_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
