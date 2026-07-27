<?php

use App\Models\Deployment;
use App\Services\DeploymentAccessService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('operations', fn ($user) => $user->hasPermission('deployments.view') || $user->hasPermission('deployments.manage') || $user->hasPermission('deployments.dispatch.view') || $user->hasPermission('deployments.dispatch.manage') || $user->hasPermission('status.view'));

Broadcast::channel('deployment-requests', fn ($user) => $user->hasPermission('deployments.manage'));

Broadcast::channel('admin.system', fn ($user) => $user->hasPermission('system.health.view'));

Broadcast::channel('admin.routing', fn ($user) => $user->hasPermission('system.routing.view') || $user->hasPermission('system.routing.manage'));

Broadcast::channel('deployments.{deploymentId}', function ($user, string $deploymentId): bool {
    $deployment = Deployment::query()->find($deploymentId);

    return $deployment !== null && app(DeploymentAccessService::class)->canViewDeployment($user, $deployment);
});

Broadcast::channel('users.{userId}', fn ($user, string $userId) => (string) $user->id === $userId || $user->hasPermission('users.view'));
