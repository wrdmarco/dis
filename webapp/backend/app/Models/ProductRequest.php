<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ProductRequest extends Model
{
    use UsesUlids;

    public const TYPES = ['feature', 'change', 'bug'];

    public const ACTIVE_STATUSES = ['open', 'in_progress'];

    public const TERMINAL_STATUSES = ['resolved', 'rejected'];

    public const STATUSES = [
        ...self::ACTIVE_STATUSES,
        ...self::TERMINAL_STATUSES,
    ];

    protected $fillable = [
        'requester_id',
        'requester_name_snapshot',
        'type',
        'title',
        'description',
        'status',
        'resolution_note',
        'resolved_by',
        'resolved_at',
        'updated_by',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ProductRequestStatusHistory::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->requester_id !== null && $this->requester_id === $user->id;
    }
}
