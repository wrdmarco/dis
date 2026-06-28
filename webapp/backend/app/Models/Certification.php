<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Certification extends Model
{
    use SoftDeletes;
    use UsesUlids;

    protected $fillable = ['code', 'name', 'description', 'is_required_for_dispatch', 'warning_days_before_expiry'];

    protected function casts(): array
    {
        return ['is_required_for_dispatch' => 'boolean'];
    }

    public function userCertifications(): HasMany
    {
        return $this->hasMany(UserCertification::class);
    }
}
