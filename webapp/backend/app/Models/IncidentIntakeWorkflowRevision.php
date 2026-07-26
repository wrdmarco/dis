<?php

namespace App\Models;

use App\Models\Concerns\UsesUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class IncidentIntakeWorkflowRevision extends Model
{
    use UsesUlids;

    protected $fillable = [
        'version',
        'status',
        'draft_marker',
        'lock_version',
        'configuration',
        'created_by',
        'updated_by',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'lock_version' => 'integer',
            'configuration' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function dossiers(): HasMany
    {
        return $this->hasMany(IncidentIntakeDossier::class, 'workflow_revision_id');
    }
}
