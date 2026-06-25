<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;

final class AuditLog extends Model
{
    use UsesUlids;

    public $timestamps = false;

    protected $fillable = ['actor_id', 'action', 'target_type', 'target_id', 'ip_address', 'user_agent', 'metadata', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }
}

