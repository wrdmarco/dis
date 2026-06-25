<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserCertification extends Model
{
    use UsesUlids;

    protected $fillable = ['user_id', 'certification_id', 'issued_at', 'expires_at', 'certificate_number', 'status', 'verified_by', 'verified_at'];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_date', 'expires_at' => 'immutable_date', 'verified_at' => 'immutable_datetime'];
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(Certification::class);
    }
}

