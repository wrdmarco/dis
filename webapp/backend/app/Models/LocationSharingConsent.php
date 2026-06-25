<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LocationSharingConsent extends Model
{
    use UsesUlids;

    protected $fillable = ['incident_id', 'user_id', 'is_active', 'consented_at', 'revoked_at', 'declined_at', 'refusal_reason'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'consented_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
