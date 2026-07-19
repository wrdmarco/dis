<?php

namespace App\Repositories;

use App\Models\WallboardMediaFolder;
use Illuminate\Database\Eloquent\Collection;

/** @extends BaseRepository<WallboardMediaFolder> */
final class WallboardMediaFolderRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return WallboardMediaFolder::class;
    }

    /** @return Collection<int, WallboardMediaFolder> */
    public function allForManagement(): Collection
    {
        return WallboardMediaFolder::query()
            ->withCount(['children', 'assets'])
            ->orderBy('parent_scope')
            ->orderBy('normalized_name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, WallboardMediaFolder> */
    public function lockAll(): Collection
    {
        return WallboardMediaFolder::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function lockFolder(string $id): WallboardMediaFolder
    {
        return WallboardMediaFolder::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
