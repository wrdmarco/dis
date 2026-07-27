<?php

namespace App\Repositories;

use App\Models\DeploymentRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class DeploymentRequestRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return DeploymentRequest::class;
    }

    /** @param list<string> $statuses */
    public function search(array $statuses, int $perPage): LengthAwarePaginator
    {
        return DeploymentRequest::query()
            ->with(['workflowRevision', 'deployment'])
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->latest('updated_at')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function lock(string $id): DeploymentRequest
    {
        return DeploymentRequest::query()
            ->with('workflowRevision')
            ->lockForUpdate()
            ->findOrFail($id);
    }

    public function forDeployment(string $deploymentId): ?DeploymentRequest
    {
        return DeploymentRequest::query()
            ->with('workflowRevision')
            ->where('deployment_id', $deploymentId)
            ->first();
    }

    public function lockForDeployment(string $deploymentId): ?DeploymentRequest
    {
        return DeploymentRequest::query()
            ->with('workflowRevision')
            ->where('deployment_id', $deploymentId)
            ->lockForUpdate()
            ->first();
    }
}
