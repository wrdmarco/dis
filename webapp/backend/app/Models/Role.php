<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Role extends Model
{
    use UsesUlids;

    protected $fillable = ['name', 'display_name', 'description', 'requires_two_factor'];

    protected function casts(): array
    {
        return ['requires_two_factor' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['assigned_by', 'created_at']);
    }
}
