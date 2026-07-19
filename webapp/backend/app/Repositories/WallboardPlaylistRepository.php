<?php

namespace App\Repositories;

use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<WallboardPlaylist>
 */
final class WallboardPlaylistRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return WallboardPlaylist::class;
    }

    /** @return Collection<int, WallboardPlaylist> */
    public function allForManagement(): Collection
    {
        return WallboardPlaylist::query()
            ->withCount('wallboards')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function findForManagement(string $id): WallboardPlaylist
    {
        return WallboardPlaylist::query()
            ->withCount('wallboards')
            ->findOrFail($id);
    }

    public function lockPlaylist(string $id): WallboardPlaylist
    {
        return WallboardPlaylist::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return Collection<int, Wallboard> */
    public function lockLinkedWallboards(string $playlistId): Collection
    {
        return Wallboard::query()
            ->where('playlist_id', $playlistId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function linkedWallboardsExist(string $playlistId): bool
    {
        return Wallboard::query()->where('playlist_id', $playlistId)->exists();
    }

    public function linkedWallboardsCount(string $playlistId): int
    {
        return Wallboard::query()->where('playlist_id', $playlistId)->count();
    }
}
