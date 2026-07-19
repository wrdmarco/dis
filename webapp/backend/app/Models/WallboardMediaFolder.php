<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WallboardMediaFolder extends Model
{
    use UsesUlids;

    public const ROOT_SCOPE = '00000000000000000000000000';

    protected $fillable = [
        'parent_id',
        'parent_scope',
        'name',
        'normalized_name',
        'version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(WallboardMediaAsset::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
