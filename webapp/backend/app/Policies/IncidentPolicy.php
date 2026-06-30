<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

final class IncidentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('incidents.view');
    }

    public function view(User $actor, Incident $incident): bool
    {
        return $actor->hasPermission('incidents.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('incidents.manage');
    }

    public function update(User $actor, Incident $incident): bool
    {
        return ! in_array($incident->status, ['resolved', 'cancelled'], true) && $actor->hasPermission('incidents.manage');
    }

    public function delete(User $actor, Incident $incident): bool
    {
        return $actor->hasPermission('incidents.delete');
    }
}
