<?php

namespace App\Policies;

use App\Models\DispatchRequest;
use App\Models\User;
use App\Services\DeploymentAccessService;

final class DispatchRequestPolicy
{
    public function __construct(private readonly DeploymentAccessService $access) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('deployments.dispatch.view') || $actor->hasPermission('deployments.assigned.view');
    }

    public function view(User $actor, DispatchRequest $dispatch): bool
    {
        return $this->access->canViewDispatch($actor, $dispatch);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('deployments.dispatch.manage');
    }

    public function update(User $actor, DispatchRequest $dispatch): bool
    {
        return ! in_array($dispatch->status, ['cancelled'], true) && $actor->hasPermission('deployments.dispatch.manage');
    }
}
