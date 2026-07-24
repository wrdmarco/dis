<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TestAlertScheduleDelivery extends Model
{
    use UsesUlids;

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = [
        'test_alert_schedule_run_id',
        'user_id',
        'dispatch_request_id',
        'status',
        'attempts',
        'last_error_code',
        'last_attempted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TestAlertScheduleRun::class, 'test_alert_schedule_run_id');
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
