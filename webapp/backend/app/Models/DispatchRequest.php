<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DispatchRequest extends Model
{
    use UsesUlids;

    protected $fillable = [
        'incident_id',
        'requested_by',
        'requested_by_name',
        'requested_by_email',
        'target_team_id',
        'status',
        'priority',
        'message',
        'includes_unavailable_recipients',
        'preannounced_at',
        'sent_at',
        'cancelled_at',
        'send_status',
        'send_queued_at',
        'send_released_at',
    ];

    protected function casts(): array
    {
        return [
            'includes_unavailable_recipients' => 'boolean',
            'preannounced_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'send_queued_at' => 'immutable_datetime',
            'send_released_at' => 'immutable_datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function targetTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'target_team_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(DispatchRecipient::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DispatchMessage::class);
    }
}
