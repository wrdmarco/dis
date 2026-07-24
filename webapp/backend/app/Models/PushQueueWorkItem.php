<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;

final class PushQueueWorkItem extends Model
{
    use UsesUlids;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RETRYING = 'retrying';

    protected $fillable = [
        'queue_job_id',
        'safe_message_type',
        'dispatch_push_outbox_id',
        'status',
        'attempts',
        'error_code',
        'queued_at',
        'processing_started_at',
        'next_attempt_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'queued_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
