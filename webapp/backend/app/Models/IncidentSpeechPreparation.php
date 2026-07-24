<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IncidentSpeechPreparation extends Model
{
    use UsesUlids;

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NOT_SCHEDULED = 'not_scheduled';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_READY = 'ready';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DISABLED,
        self::STATUS_NOT_SCHEDULED,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_READY,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'incident_id',
        'phase',
        'source_fingerprint_hmac',
        'status',
        'progress_percent',
        'error_code',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
