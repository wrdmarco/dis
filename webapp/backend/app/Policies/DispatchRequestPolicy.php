<?php

namespace App\Policies;

use App\Models\DispatchRequest;
use App\Models\User;
use App\Services\IncidentAccessService;

final class DispatchRequestPolicy
{
    public function __construct(private readonly IncidentAccessService $access) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('incidents.dispatch.view') || $actor->hasPermission('incidents.assigned.view');
    }

    public function view(User $actor, DispatchRequest $dispatch): bool
    {
        return $this->access->canViewDispatch($actor, $dispatch);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('incidents.dispatch.manage');
    }

    public function update(User $actor, DispatchRequest $dispatch): bool
    {
        return ! in_array($dispatch->status, ['cancelled'], true) && $actor->hasPermission('incidents.dispatch.manage');
    }
}
