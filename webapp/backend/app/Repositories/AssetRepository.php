<?php

namespace App\Repositories;

use App\Models\Asset;

final class AssetRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return Asset::class;
    }
}

