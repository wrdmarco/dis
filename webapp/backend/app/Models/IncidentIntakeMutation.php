<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class IncidentIntakeMutation extends Model
{
    use UsesUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'dossier_id',
        'actor_id',
        'client_mutation_id',
        'operation',
        'request_hash',
        'response_payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(IncidentIntakeDossier::class, 'dossier_id');
    }
}
