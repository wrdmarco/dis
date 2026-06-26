<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class DroneType extends Model
{
    use SoftDeletes;
    use UsesUlids;

    protected $fillable = ['manufacturer', 'model', 'has_thermal', 'has_spotlight', 'has_speaker', 'is_active', 'notes'];

    protected function casts(): array
    {
        return [
            'has_thermal' => 'boolean',
            'has_spotlight' => 'boolean',
            'has_speaker' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
