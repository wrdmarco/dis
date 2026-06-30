<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

final class AvailabilityStatus extends Model
{
    use UsesUlids;

    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'status',
        'is_available',
        'is_system_applied',
        'changed_by',
        'changed_by_name',
        'changed_by_email',
        'reason',
        'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'is_available' => 'boolean',
            'is_system_applied' => 'boolean',
            'effective_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param Builder<AvailabilityStatus> $query
     * @return Builder<AvailabilityStatus>
     */
    public function scopeLatestPerUser(Builder $query): Builder
    {
        $latestStatusIds = DB::query()
            ->fromSub(
                self::query()
                    ->select('id')
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY effective_at DESC, created_at DESC, id DESC) AS status_rank'),
                'ranked_statuses',
            )
            ->where('status_rank', 1)
            ->select('id');

        return $query->whereIn('id', $latestStatusIds);
    }
}
