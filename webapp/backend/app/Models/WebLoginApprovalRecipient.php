<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WebLoginApprovalRecipient extends Model
{
    use UsesUlids;

    protected $fillable = [
        'web_login_approval_id',
        'fcm_token_id',
        'personal_access_token_id',
        'delivery_status',
        'last_sent_at',
        'delivery_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'immutable_datetime',
            'delivery_failed_at' => 'immutable_datetime',
        ];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(WebLoginApproval::class, 'web_login_approval_id');
    }

    public function fcmToken(): BelongsTo
    {
        return $this->belongsTo(FcmToken::class);
    }

    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }
}
