<?php

namespace App\Repositories;

use App\Models\IncidentIntakeWorkflowRevision;
use Illuminate\Database\Eloquent\Collection;

final class IncidentIntakeWorkflowRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return IncidentIntakeWorkflowRevision::class;
    }

    public function draft(bool $lock = false): ?IncidentIntakeWorkflowRevision
    {
        $query = IncidentIntakeWorkflowRevision::query()->where('status', 'draft')->latest();

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function published(): ?IncidentIntakeWorkflowRevision
    {
        return IncidentIntakeWorkflowRevision::query()
            ->where('status', 'published')
            ->latest('version')
            ->first();
    }

    /** @return Collection<int, IncidentIntakeWorkflowRevision> */
    public function history(): Collection
    {
        return IncidentIntakeWorkflowRevision::query()
            ->where('status', 'published')
            ->latest('version')
            ->limit(50)
            ->get();
    }
}
