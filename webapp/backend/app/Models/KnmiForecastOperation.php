<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class KnmiForecastOperation extends Model
{
    use UsesUlids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    public const ACTIVE_KEY = 'knmi-harmonie-p1';

    public const STATE_QUEUED = 'queued';

    public const STATE_RUNNING = 'running';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'state',
        'stage',
        'active_key',
        'message',
        'progress_percent',
        'downloaded_bytes',
        'total_bytes',
        'source_filename',
        'unchanged',
        'requested_by',
        'snapshot_id',
        'error_code',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'downloaded_bytes' => 'integer',
            'total_bytes' => 'integer',
            'unchanged' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(KnmiForecastSnapshot::class, 'snapshot_id');
    }

    public function isActive(): bool
    {
        return in_array($this->state, [self::STATE_QUEUED, self::STATE_RUNNING], true);
    }
}
