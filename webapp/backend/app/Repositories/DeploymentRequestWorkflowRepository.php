<?php

namespace App\Repositories;

use App\Models\DeploymentRequestWorkflowRevision;
use Illuminate\Database\Eloquent\Collection;

final class DeploymentRequestWorkflowRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return DeploymentRequestWorkflowRevision::class;
    }

    public function draft(bool $lock = false): ?DeploymentRequestWorkflowRevision
    {
        $query = DeploymentRequestWorkflowRevision::query()->where('status', 'draft')->latest();

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function published(): ?DeploymentRequestWorkflowRevision
    {
        return DeploymentRequestWorkflowRevision::query()
            ->where('status', 'published')
            ->latest('version')
            ->first();
    }

    /** @return Collection<int, DeploymentRequestWorkflowRevision> */
    public function history(): Collection
    {
        return DeploymentRequestWorkflowRevision::query()
            ->where('status', 'published')
            ->latest('version')
            ->limit(50)
            ->get();
    }
}
