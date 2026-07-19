<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WallboardPairingRequest extends Model
{
    use UsesUlids;

    protected $fillable = [
        'code_hash',
        'secret_hash',
        'device_name',
        'request_ip',
        'request_user_agent',
        'wallboard_id',
        'approved_by',
        'approved_at',
        'wallboard_session_id',
        'consumed_at',
        'consumed_ip',
        'consumed_user_agent',
        'expires_at',
    ];

    protected $hidden = [
        'code_hash',
        'secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function wallboard(): BelongsTo
    {
        return $this->belongsTo(Wallboard::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function wallboardSession(): BelongsTo
    {
        return $this->belongsTo(WallboardSession::class);
    }
}
