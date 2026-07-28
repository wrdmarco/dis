<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserNotification extends Model
{
    use UsesUlids;

    public const TYPE_CERTIFICATION_EXPIRING = 'certification_expiring';

    public const TYPE_CERTIFICATION_EXPIRED = 'certification_expired';

    public const TYPE_ASSET_MAINTENANCE_DUE = 'asset_maintenance_due';

    public const TYPE_ASSET_MAINTENANCE_OVERDUE = 'asset_maintenance_overdue';

    public const TYPE_PRODUCT_REQUEST_STATUS = 'product_request_status';

    public const REMINDER_TYPES = [
        self::TYPE_CERTIFICATION_EXPIRING,
        self::TYPE_CERTIFICATION_EXPIRED,
        self::TYPE_ASSET_MAINTENANCE_DUE,
        self::TYPE_ASSET_MAINTENANCE_OVERDUE,
    ];

    protected $fillable = [
        'user_id',
        'type',
        'tone',
        'title',
        'message',
        'action_url',
        'source_type',
        'source_id',
        'deduplication_key',
        'occurred_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
