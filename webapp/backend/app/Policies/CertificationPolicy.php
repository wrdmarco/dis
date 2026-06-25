<?php

namespace App\Policies;

use App\Models\Certification;
use App\Models\User;

final class CertificationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('certifications.view');
    }

    public function view(User $actor, Certification $certification): bool
    {
        return $actor->hasPermission('certifications.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('certifications.manage');
    }

    public function update(User $actor, Certification $certification): bool
    {
        return $actor->hasPermission('certifications.manage');
    }
}

