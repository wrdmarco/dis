<?php

namespace App\Policies;

use App\Models\Deployment;
use App\Models\User;
use App\Services\DeploymentAccessService;

final class DeploymentPolicy
{
    public function __construct(private readonly DeploymentAccessService $access) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->canListDeployments($actor);
    }

    public function view(User $actor, Deployment $deployment): bool
    {
        return $this->access->canViewDeployment($actor, $deployment);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('deployments.manage');
    }

    public function update(User $actor, Deployment $deployment): bool
    {
        return ! in_array($deployment->status, ['resolved', 'cancelled'], true) && $actor->hasPermission('deployments.manage');
    }

    public function delete(User $actor, Deployment $deployment): bool
    {
        return $actor->hasPermission('deployments.delete');
    }
}
