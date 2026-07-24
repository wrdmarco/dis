<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DispatchPushOutbox extends Model
{
    use UsesUlids;

    protected $table = 'dispatch_push_outbox';

    protected $fillable = [
        'deduplication_key',
        'dispatch_request_id',
        'fcm_token_id',
        'message_type',
        'title',
        'body',
        'data',
        'available_at',
        'queued_at',
        'processing_started_at',
        'retry_at',
        'delivered_at',
        'cancelled_at',
        'attempts',
        'last_attempted_at',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'available_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'retry_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    public function dispatchRequest(): BelongsTo
    {
        return $this->belongsTo(DispatchRequest::class);
    }

    public function fcmToken(): BelongsTo
    {
        return $this->belongsTo(FcmToken::class);
    }
}
