<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DeploymentRequest extends Model
{
    use UsesUlids;

    protected $fillable = [
        'workflow_revision_id',
        'deployment_id',
        'status',
        'subject_type',
        'title',
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
        return $this->belongsTo(DeploymentRequestWorkflowRevision::class, 'workflow_revision_id');
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function mutations(): HasMany
    {
        return $this->hasMany(DeploymentRequestMutation::class, 'deployment_request_id');
    }
}
