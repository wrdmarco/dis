<?php

namespace App\Services;

use App\Models\Wallboard;
use App\Models\WallboardMediaAsset;
use App\Repositories\WallboardMediaAssetRepository;
use App\Support\WallboardMediaContent;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class WallboardMediaDeliveryService
{
    public function __construct(private readonly WallboardMediaAssetRepository $assets) {}

    public function forAdmin(WallboardMediaAsset $asset): ?WallboardMediaContent
    {
        return $this->content($this->assets->findReady((string) $asset->getKey()));
    }

    public function forWallboard(Wallboard $wallboard, WallboardMediaAsset $asset): ?WallboardMediaContent
    {
        $playlistId = $wallboard->playlist_id;
        if (! is_string($playlistId) || $playlistId === '') {
            return null;
        }

        return $this->content($this->assets->authorizedForWallboard(
            (string) $asset->getKey(),
            $playlistId,
        ));
    }

    private function content(?WallboardMediaAsset $asset): ?WallboardMediaContent
    {
        if (! $asset instanceof WallboardMediaAsset
            || $asset->mime_type !== 'image/webp'
            || preg_match('/^[a-f0-9]{64}$/', (string) $asset->sha256) !== 1) {
            return null;
        }
        $root = preg_quote(trim((string) config('wallboard_media.root', 'wallboard-media'), '/'), '#');
        if (preg_match(
            '#^'.$root.'/objects/'.preg_quote((string) $asset->id, '#').'\.webp$#',
            (string) $asset->storage_path,
        ) !== 1) {
            return null;
        }

        try {
            $diskName = (string) config('wallboard_media.disk', 'local');
            $disk = Storage::disk($diskName);
            $path = (string) $asset->storage_path;
            if (! $disk->exists($path)
                || $disk->size($path) !== (int) $asset->byte_size) {
                return null;
            }
            $absolutePath = $disk->path($path);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
            $sha256 = hash_file('sha256', $absolutePath);
            if ($mime !== 'image/webp'
                || ! is_string($sha256)
                || ! hash_equals((string) $asset->sha256, $sha256)) {
                return null;
            }

            return new WallboardMediaContent(
                disk: $diskName,
                path: $path,
                contentType: 'image/webp',
                byteSize: (int) $asset->byte_size,
                etag: '"'.$sha256.'"',
            );
        } catch (Throwable) {
            return null;
        }
    }
}
