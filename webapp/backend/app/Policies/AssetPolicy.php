<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

final class AssetPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('assets.view');
    }

    public function view(User $actor, Asset $asset): bool
    {
        return $actor->hasPermission('assets.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('assets.manage');
    }

    public function update(User $actor, Asset $asset): bool
    {
        return $actor->hasPermission('assets.manage');
    }
}

