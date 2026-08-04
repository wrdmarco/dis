<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WebLoginApproval extends Model
{
    use UsesUlids;

    protected $fillable = [
        'user_id',
        'browser_session_hash',
        'auth_session_version',
        'status',
        'verification_number',
        'request_device',
        'request_ip',
        'requested_at',
        'expires_at',
        'approved_at',
        'denied_at',
        'consumed_at',
        'cancelled_at',
        'approved_by_fcm_token_id',
        'approved_by_personal_access_token_id',
    ];

    protected $hidden = [
        'browser_session_hash',
        'auth_session_version',
        'approved_by_fcm_token_id',
        'approved_by_personal_access_token_id',
    ];

    protected function casts(): array
    {
        return [
            'auth_session_version' => 'integer',
            'requested_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'denied_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(WebLoginApprovalRecipient::class);
    }

    public function approvingDevice(): BelongsTo
    {
        return $this->belongsTo(FcmToken::class, 'approved_by_fcm_token_id');
    }

    public function approvingAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'approved_by_personal_access_token_id');
    }
}
