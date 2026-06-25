<?php

namespace App\Repositories;

use App\Models\Certification;

final class CertificationRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return Certification::class;
    }
}

