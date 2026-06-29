<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetMailLog extends Model
{
    use UsesUlids;

    protected $fillable = ['asset_id', 'user_id', 'notification_type', 'expires_at', 'sent_for_date', 'sent_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_date',
            'sent_for_date' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
