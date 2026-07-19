<?php

namespace App\Repositories;

use App\Models\WallboardMediaPlaylist;
use App\Models\WallboardMediaPlaylistUsage;
use Illuminate\Database\Eloquent\Collection;

/** @extends BaseRepository<WallboardMediaPlaylist> */
final class WallboardMediaPlaylistRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return WallboardMediaPlaylist::class;
    }

    /** @return Collection<int, WallboardMediaPlaylist> */
    public function allForManagement(): Collection
    {
        return WallboardMediaPlaylist::query()
            ->with(['items.asset.folder:id,name'])
            ->withCount('usages')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function findForManagement(string $id): WallboardMediaPlaylist
    {
        return WallboardMediaPlaylist::query()
            ->with(['items.asset.folder:id,name'])
            ->withCount('usages')
            ->findOrFail($id);
    }

    public function lockPlaylist(string $id): WallboardMediaPlaylist
    {
        return WallboardMediaPlaylist::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** @return list<string> */
    public function wallboardPlaylistIdsUsing(string $mediaPlaylistId): array
    {
        return WallboardMediaPlaylistUsage::query()
            ->where('media_playlist_id', $mediaPlaylistId)
            ->orderBy('wallboard_playlist_id')
            ->pluck('wallboard_playlist_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function isUsed(string $mediaPlaylistId): bool
    {
        return WallboardMediaPlaylistUsage::query()
            ->where('media_playlist_id', $mediaPlaylistId)
            ->exists();
    }
}
