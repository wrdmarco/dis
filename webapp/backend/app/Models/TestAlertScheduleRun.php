<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TestAlertScheduleRun extends Model
{
    use UsesUlids;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = [
        'run_key',
        'scheduled_for',
        'retry_until',
        'message',
        'status',
        'target_count',
        'sent_count',
        'skipped_count',
        'failed_count',
        'expired_count',
        'initialized_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'retry_until' => 'immutable_datetime',
            'message' => 'encrypted',
            'target_count' => 'integer',
            'sent_count' => 'integer',
            'skipped_count' => 'integer',
            'failed_count' => 'integer',
            'expired_count' => 'integer',
            'initialized_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(TestAlertScheduleDelivery::class);
    }
}
