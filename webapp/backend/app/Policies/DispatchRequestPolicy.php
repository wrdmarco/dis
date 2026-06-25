<?php

namespace App\Policies;

use App\Models\DispatchRequest;
use App\Models\User;

final class DispatchRequestPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('dispatch.view');
    }

    public function view(User $actor, DispatchRequest $dispatch): bool
    {
        return $actor->hasPermission('dispatch.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('dispatch.manage');
    }

    public function update(User $actor, DispatchRequest $dispatch): bool
    {
        return ! in_array($dispatch->status, ['cancelled'], true) && $actor->hasPermission('dispatch.manage');
    }
}

