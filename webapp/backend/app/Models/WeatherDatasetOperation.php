<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WeatherDatasetOperation extends Model
{
    use UsesUlids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    public const STATE_QUEUED = 'queued';

    public const STATE_RUNNING = 'running';

    public const STATE_SUCCEEDED = 'succeeded';

    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'dataset_key',
        'dataset_keys',
        'active_key',
        'scheduled',
        'state',
        'stage',
        'message',
        'progress_percent',
        'result',
        'error_code',
        'error_message',
        'requested_by',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'dataset_keys' => 'array',
            'scheduled' => 'boolean',
            'progress_percent' => 'integer',
            'result' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isActive(): bool
    {
        return in_array($this->state, [self::STATE_QUEUED, self::STATE_RUNNING], true);
    }
}
