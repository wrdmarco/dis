<?php

namespace App\Services;

use App\Models\WallboardMediaAsset;
use App\Models\WallboardMediaPlaylistUsage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class WallboardMediaCleanupService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly WallboardMediaCoordinationService $coordination,
    ) {}

    /** @return array{staging_deleted: int, objects_deleted: int, usages_deleted: int} */
    public function cleanup(): array
    {
        return Cache::lock('wallboard-media:cleanup', 300)->block(1, function (): array {
            $disk = Storage::disk((string) config('wallboard_media.disk', 'local'));
            $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');
            $cutoff = now()->subSeconds(max(
                3600,
                (int) config('wallboard_media.orphan_grace_seconds', 24 * 60 * 60),
            ))->getTimestamp();
            $protected = [];
            foreach (WallboardMediaAsset::query()->get(['storage_path', 'thumbnail_storage_path']) as $asset) {
                $protected[(string) $asset->storage_path] = true;
                if ($asset->thumbnail_storage_path !== null) {
                    $protected[(string) $asset->thumbnail_storage_path] = true;
                }
            }

            $stagingDeleted = $this->deleteEligible(
                $disk,
                $disk->files($root.'/staging'),
                $cutoff,
                '#^'.preg_quote($root, '#').'/staging/upload-[A-Za-z0-9._-]+$#',
                [],
            );
            $objectsDeleted = $this->deleteEligible(
                $disk,
                $disk->files($root.'/objects'),
                $cutoff,
                '#^'.preg_quote($root, '#').'/objects/[0-9A-HJKMNP-TV-Z]{26}(?:\.thumbnail)?\.(?:webp|mp4)$#',
                $protected,
            );
            $usagesDeleted = DB::transaction(function (): int {
                $this->coordination->lock();

                return WallboardMediaPlaylistUsage::query()
                    ->whereNotExists(fn ($query) => $query
                        ->selectRaw('1')
                        ->from('wallboard_playlists')
                        ->whereColumn(
                            'wallboard_playlists.id',
                            'wallboard_media_playlist_usages.wallboard_playlist_id',
                        ))
                    ->delete();
            }, 3);
            if ($stagingDeleted + $objectsDeleted + $usagesDeleted > 0) {
                $this->auditService->record('wallboard_media.storage.cleaned', 'wallboard_media', null, [
                    'staging_deleted' => $stagingDeleted,
                    'objects_deleted' => $objectsDeleted,
                    'usages_deleted' => $usagesDeleted,
                ]);
            }

            return [
                'staging_deleted' => $stagingDeleted,
                'objects_deleted' => $objectsDeleted,
                'usages_deleted' => $usagesDeleted,
            ];
        });
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, bool>  $protected
     */
    private function deleteEligible(
        Filesystem $disk,
        array $paths,
        int $cutoff,
        string $pattern,
        array $protected,
    ): int {
        $deleted = 0;
        foreach ($paths as $path) {
            if (preg_match($pattern, $path) !== 1 || isset($protected[$path])) {
                continue;
            }
            try {
                if ($disk->lastModified($path) > $cutoff || ! $disk->delete($path)) {
                    continue;
                }
                $deleted++;
            } catch (Throwable) {
                // Fail closed for this object. A later scheduled run retries it.
            }
        }

        return $deleted;
    }
}
