<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DispatchRecipient extends Model
{
    use UsesUlids;

    protected $fillable = [
        'dispatch_request_id',
        'user_id',
        'user_name',
        'user_email',
        'response_status',
        'response_note',
        'notified_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return ['notified_at' => 'immutable_datetime', 'responded_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dispatchRequest(): BelongsTo
    {
        return $this->belongsTo(DispatchRequest::class);
    }
}
