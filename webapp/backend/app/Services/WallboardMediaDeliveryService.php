<?php

namespace App\Services;

use App\Models\Wallboard;
use App\Models\WallboardMediaAsset;
use App\Repositories\WallboardMediaAssetRepository;
use App\Support\WallboardMediaContent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class WallboardMediaDeliveryService
{
    public function __construct(private readonly WallboardMediaAssetRepository $assets) {}

    public function forAdmin(WallboardMediaAsset $asset): ?WallboardMediaContent
    {
        return $this->content($this->assets->findReady((string) $asset->getKey()));
    }

    public function thumbnailForAdmin(WallboardMediaAsset $asset): ?WallboardMediaContent
    {
        $ready = $this->assets->findReady((string) $asset->getKey());
        if (! $ready instanceof WallboardMediaAsset
            || ($ready->kind ?: WallboardMediaAsset::KIND_IMAGE) !== WallboardMediaAsset::KIND_IMAGE) {
            return null;
        }
        if ($ready->thumbnail_storage_path === null) {
            return $this->content($ready);
        }

        return $this->verifiedContent(
            path: (string) $ready->thumbnail_storage_path,
            expectedPath: $this->objectPath($ready, '.thumbnail.webp'),
            mimeType: (string) $ready->thumbnail_mime_type,
            byteSize: (int) $ready->thumbnail_byte_size,
            sha256: (string) $ready->thumbnail_sha256,
        );
    }

    public function forWallboard(Wallboard $wallboard, WallboardMediaAsset $asset): ?WallboardMediaContent
    {
        $playlistIds = array_values(array_filter([
            $wallboard->playlist_id,
            $wallboard->active_incident_playlist_id,
        ], static fn (mixed $id): bool => is_string($id) && $id !== ''));
        if ($playlistIds === []) {
            return null;
        }

        return $this->content($this->assets->authorizedForWallboard(
            (string) $asset->getKey(),
            $playlistIds,
        ));
    }

    private function content(?WallboardMediaAsset $asset): ?WallboardMediaContent
    {
        if (! $asset instanceof WallboardMediaAsset) {
            return null;
        }
        $kind = (string) ($asset->kind ?: WallboardMediaAsset::KIND_IMAGE);
        $expectedMime = match ($kind) {
            WallboardMediaAsset::KIND_IMAGE => 'image/webp',
            WallboardMediaAsset::KIND_VIDEO => 'video/mp4',
            default => null,
        };
        if ($expectedMime === null || $asset->mime_type !== $expectedMime) {
            return null;
        }

        return $this->verifiedContent(
            path: (string) $asset->storage_path,
            expectedPath: $this->objectPath(
                $asset,
                $kind === WallboardMediaAsset::KIND_VIDEO ? '.mp4' : '.webp',
            ),
            mimeType: $expectedMime,
            byteSize: (int) $asset->byte_size,
            sha256: (string) $asset->sha256,
        );
    }

    private function verifiedContent(
        string $path,
        string $expectedPath,
        string $mimeType,
        int $byteSize,
        string $sha256,
    ): ?WallboardMediaContent {
        if ($path !== $expectedPath
            || $byteSize < 1
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return null;
        }

        try {
            $diskName = (string) config('wallboard_media.disk', 'local');
            $disk = Storage::disk($diskName);
            if (! $disk->exists($path)
                || $disk->size($path) !== $byteSize) {
                return null;
            }
            $absolutePath = $disk->path($path);
            $lastModified = $disk->lastModified($path);
            $cacheKey = 'wallboard-media:verified:'.hash(
                'sha256',
                implode('|', [$diskName, $path, $byteSize, $lastModified, $sha256, $mimeType]),
            );
            $verified = Cache::remember($cacheKey, 300, static function () use (
                $absolutePath,
                $mimeType,
                $sha256,
            ): bool {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
                $actualSha256 = hash_file('sha256', $absolutePath);

                return $mime === $mimeType
                    && is_string($actualSha256)
                    && hash_equals($sha256, $actualSha256);
            });
            if ($verified !== true) {
                return null;
            }

            return new WallboardMediaContent(
                disk: $diskName,
                path: $path,
                contentType: $mimeType,
                byteSize: $byteSize,
                etag: '"'.$sha256.'"',
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function objectPath(WallboardMediaAsset $asset, string $suffix): string
    {
        $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');

        return $root.'/objects/'.(string) $asset->id.$suffix;
    }
}
