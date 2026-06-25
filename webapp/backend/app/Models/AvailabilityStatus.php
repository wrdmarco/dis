<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AvailabilityStatus extends Model
{
    use UsesUlids;

    protected $fillable = ['user_id', 'status', 'is_available', 'is_system_applied', 'changed_by', 'reason', 'effective_at'];

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
}
