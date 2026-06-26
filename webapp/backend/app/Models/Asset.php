<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Asset extends Model
{
    use SoftDeletes;
    use UsesUlids;

    protected $fillable = ['asset_tag', 'name', 'type', 'drone_type_id', 'status', 'serial_number', 'maintenance_due_at', 'notes'];

    protected function casts(): array
    {
        return ['maintenance_due_at' => 'immutable_date'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function droneType(): BelongsTo
    {
        return $this->belongsTo(DroneType::class);
    }
}
