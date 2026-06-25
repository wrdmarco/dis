<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;

final class LocationSharingConsent extends Model
{
    use UsesUlids;

    protected $fillable = ['incident_id', 'user_id', 'is_active', 'consented_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'consented_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}

