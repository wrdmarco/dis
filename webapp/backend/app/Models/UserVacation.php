<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserVacation extends Model
{
    use UsesUlids;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = ['user_id', 'starts_at', 'ends_at', 'status', 'note', 'created_by', 'cancelled_by', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_date',
            'ends_at' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param Builder<UserVacation> $query
     * @return Builder<UserVacation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_ACTIVE]);
    }
}
