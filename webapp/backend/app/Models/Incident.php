<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Incident extends Model
{
    use SoftDeletes;
    use UsesUlids;

    protected $fillable = [
        'reference',
        'title',
        'description',
        'reporter_name',
        'reporter_phone',
        'requesting_organization',
        'requesting_unit',
        'on_scene_contact_name',
        'on_scene_contact_phone',
        'on_scene_contact_role',
        'operational_objective',
        'required_resources',
        'required_qualification',
        'priority',
        'status',
        'is_test',
        'location_label',
        'latitude',
        'longitude',
        'drone_flight_context',
        'report_pdf_path',
        'report_generated_at',
        'report_generation_error',
        'created_by',
        'created_by_name',
        'created_by_email',
        'coordinator_id',
        'coordinator_name',
        'coordinator_email',
        'team_id',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'drone_flight_context' => 'array',
            'is_test' => 'boolean',
            'report_generated_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'incident_team')->withPivot('created_at');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(IncidentStatusHistory::class);
    }

    public function dispatchRequests(): HasMany
    {
        return $this->hasMany(DispatchRequest::class);
    }
}
