<?php

namespace App\Repositories;

use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use Closure;
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

    public function findForContentRefresh(string $id): ?WallboardPlaylist
    {
        return WallboardPlaylist::query()
            ->select(['id', 'configuration'])
            ->find($id);
    }

    /** @param Closure(Collection<int, WallboardPlaylist>): void $callback */
    public function chunkForContentRefresh(Closure $callback, int $size = 50): void
    {
        WallboardPlaylist::query()
            ->select(['id', 'configuration'])
            ->orderBy('id')
            ->chunkById($size, $callback);
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
