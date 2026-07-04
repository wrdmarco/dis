<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AvailabilityOverride extends Model
{
    use UsesUlids;

    protected $fillable = ['user_id', 'starts_at', 'ends_at', 'day_part', 'is_available', 'note', 'created_by'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_date',
            'ends_at' => 'immutable_date',
            'is_available' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
