<?php

namespace App\Repositories;

use App\Models\WallboardMediaAsset;
use App\Support\WallboardConfiguration;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
        ?string $kind = null,
        ?string $status = null,
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
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
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
    public function readyAssets(array $ids): Collection
    {
        return WallboardMediaAsset::query()
            ->whereIn('id', $ids)
            ->where('status', WallboardMediaAsset::STATUS_READY)
            ->orderBy('id')
            ->get();
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
                WallboardMediaAsset::STATUS_FAILED,
            ])
            ->sum(DB::raw('byte_size + COALESCE(thumbnail_byte_size, 0)'));
    }

    public function activeCount(): int
    {
        return WallboardMediaAsset::query()
            ->whereIn('status', [
                WallboardMediaAsset::STATUS_PROCESSING,
                WallboardMediaAsset::STATUS_READY,
                WallboardMediaAsset::STATUS_FAILED,
            ])
            ->count();
    }

    public function usedByPlaylist(string $assetId): bool
    {
        return WallboardMediaAsset::query()
            ->whereKey($assetId)
            ->where(fn ($query) => $query
                ->whereHas('playlistItems')
                ->orWhereHas('wallboardUsages'))
            ->exists();
    }

    /** @param list<string> $wallboardPlaylistIds */
    public function authorizedForWallboard(string $assetId, array $wallboardPlaylistIds): ?WallboardMediaAsset
    {
        $wallboardPlaylistIds = array_values(array_unique(array_filter(
            $wallboardPlaylistIds,
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
        if ($wallboardPlaylistIds === []) {
            return null;
        }

        return WallboardMediaAsset::query()
            ->whereKey($assetId)
            ->where('status', WallboardMediaAsset::STATUS_READY)
            ->where(function ($query) use ($wallboardPlaylistIds): void {
                $query->where(function ($image) use ($wallboardPlaylistIds): void {
                    $image->where('kind', WallboardMediaAsset::KIND_IMAGE)
                        ->whereHas('playlistItems.playlist.usages', fn ($usage) => $usage->whereIn(
                            'wallboard_playlist_id',
                            $wallboardPlaylistIds,
                        ));
                })->orWhere(function ($video) use ($wallboardPlaylistIds): void {
                    $video->where('kind', WallboardMediaAsset::KIND_VIDEO)
                        ->where('mime_type', 'video/mp4')
                        ->whereBetween('duration_seconds', [
                            WallboardConfiguration::MIN_VIDEO_DURATION_SECONDS,
                            WallboardConfiguration::MAX_VIDEO_DURATION_SECONDS,
                        ])
                        ->whereHas('wallboardUsages', fn ($usage) => $usage->whereIn(
                            'wallboard_playlist_id',
                            $wallboardPlaylistIds,
                        ));
                });
            })
            ->first();
    }
}
