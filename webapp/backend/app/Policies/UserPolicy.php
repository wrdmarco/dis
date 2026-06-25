<?php

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->id === $user->id || $actor->hasPermission('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.manage');
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->id !== $user->id && $actor->hasPermission('users.manage');
    }
}

