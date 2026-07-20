<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PilotIncidentReport extends Model
{
    use UsesUlids;

    protected $fillable = [
        'incident_id',
        'user_id',
        'user_name',
        'user_email',
        'status',
        'summary',
        'observations',
        'actions_taken',
        'result',
        'issues',
        'equipment_used',
        'flight_minutes',
        'custom_fields',
        'drone_usage_snapshot',
        'prepared_at',
        'submitted_at',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'flight_minutes' => 'integer',
            'custom_fields' => 'array',
            'drone_usage_snapshot' => 'array',
            'prepared_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function canBeEdited(): bool
    {
        return $this->finalized_at === null;
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }
}
