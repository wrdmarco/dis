<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CertificationMailLog extends Model
{
    use UsesUlids;

    protected $fillable = ['user_certification_id', 'notification_type', 'expires_at', 'sent_for_date', 'sent_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_date',
            'sent_for_date' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function userCertification(): BelongsTo
    {
        return $this->belongsTo(UserCertification::class);
    }
}
