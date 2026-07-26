<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class IncidentIntakeDossier extends Model
{
    use UsesUlids;

    protected $fillable = [
        'workflow_revision_id',
        'incident_id',
        'status',
        'subject_type',
        'answers',
        'triage',
        'recommended_priority',
        'decided_priority',
        'priority_decision_reason',
        'selected_deployment_profile_id',
        'selected_deployment_proposal',
        'lock_version',
        'created_by',
        'updated_by',
        'closed_by',
        'close_reason',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'triage' => 'array',
            'selected_deployment_proposal' => 'array',
            'lock_version' => 'integer',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function workflowRevision(): BelongsTo
    {
        return $this->belongsTo(IncidentIntakeWorkflowRevision::class, 'workflow_revision_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function mutations(): HasMany
    {
        return $this->hasMany(IncidentIntakeMutation::class, 'dossier_id');
    }
}
