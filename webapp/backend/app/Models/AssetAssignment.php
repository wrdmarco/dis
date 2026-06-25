<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetAssignment extends Model
{
    use UsesUlids;

    public $timestamps = false;

    protected $fillable = ['asset_id', 'incident_id', 'user_id', 'assigned_by', 'assigned_at', 'released_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
