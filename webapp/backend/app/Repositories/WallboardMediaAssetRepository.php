<?php

namespace App\Repositories;

use App\Models\WallboardMediaAsset;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/** @extends BaseRepository<WallboardMediaAsset> */
final class WallboardMediaAssetRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return WallboardMediaAsset::class;
    }

    public function paginateForManagement(
        ?string $folderId,
        ?string $search,
        int $perPage,
    ): LengthAwarePaginator {
        $normalizedSearch = trim((string) $search);

        return WallboardMediaAsset::query()
            ->with('folder:id,name')
            ->withCount('playlistItems')
            ->when($folderId !== null, fn ($query) => $query->where('folder_id', $folderId))
            ->when($folderId === '', fn ($query) => $query->whereNull('folder_id'))
            ->when($normalizedSearch !== '', fn ($query) => $query->where(
                'display_name',
                'like',
                '%'.addcslashes($normalizedSearch, '%_\\').'%',
            ))
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function findForManagement(string $id): WallboardMediaAsset
    {
        return WallboardMediaAsset::query()
            ->with('folder:id,name')
            ->withCount('playlistItems')
            ->findOrFail($id);
    }

    public function lockAsset(string $id): WallboardMediaAsset
    {
        return WallboardMediaAsset::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function findReady(string $id): ?WallboardMediaAsset
    {
        return WallboardMediaAsset::query()
            ->whereKey($id)
            ->where('status', WallboardMediaAsset::STATUS_READY)
            ->first();
    }

    /** @param list<string> $ids
     * @return Collection<int, WallboardMediaAsset>
     */
    public function lockReadyAssets(array $ids): Collection
    {
        return WallboardMediaAsset::query()
            ->whereIn('id', $ids)
            ->where('status', WallboardMediaAsset::STATUS_READY)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function activeByteSize(): int
    {
        return (int) WallboardMediaAsset::query()
            ->whereIn('status', [
                WallboardMediaAsset::STATUS_PROCESSING,
                WallboardMediaAsset::STATUS_READY,
            ])
            ->sum('byte_size');
    }

    public function activeCount(): int
    {
        return WallboardMediaAsset::query()
            ->whereIn('status', [
                WallboardMediaAsset::STATUS_PROCESSING,
                WallboardMediaAsset::STATUS_READY,
            ])
            ->count();
    }

    public function usedByPlaylist(string $assetId): bool
    {
        return WallboardMediaAsset::query()
            ->whereKey($assetId)
            ->whereHas('playlistItems')
            ->exists();
    }

    public function authorizedForWallboard(string $assetId, string $wallboardPlaylistId): ?WallboardMediaAsset
    {
        return WallboardMediaAsset::query()
            ->whereKey($assetId)
            ->where('status', WallboardMediaAsset::STATUS_READY)
            ->whereHas('playlistItems.playlist.usages', fn ($query) => $query->where(
                'wallboard_playlist_id',
                $wallboardPlaylistId,
            ))
            ->first();
    }
}
