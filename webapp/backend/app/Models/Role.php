<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Role extends Model
{
    use UsesUlids;

    public const SYSTEM_ADMINISTRATOR = 'system-administrator';

    protected $fillable = ['name', 'display_name', 'description', 'requires_two_factor', 'can_use_operator_app', 'can_use_admin_app'];

    protected function casts(): array
    {
        return [
            'requires_two_factor' => 'boolean',
            'can_use_operator_app' => 'boolean',
            'can_use_admin_app' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['assigned_by', 'created_at']);
    }

    public function isSystemAdministrator(): bool
    {
        return $this->name === self::SYSTEM_ADMINISTRATOR;
    }
}
