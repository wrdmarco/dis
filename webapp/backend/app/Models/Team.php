<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Team extends Model
{
    use SoftDeletes;
    use UsesUlids;

    protected $fillable = ['code', 'name', 'type', 'parent_team_id', 'is_operational'];

    protected function casts(): array
    {
        return ['is_operational' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_team_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['assigned_by', 'created_at']);
    }
}
