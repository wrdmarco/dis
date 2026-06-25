<?php

namespace App\Repositories;

use App\Models\DispatchRequest;

final class DispatchRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return DispatchRequest::class;
    }
}

