<?php

namespace App\Services;

use App\Models\WallboardMediaAsset;
use App\Support\WallboardMediaProcessedThumbnail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class WallboardMediaThumbnailBackfillService
{
    private const CURSOR_CACHE_KEY = 'wallboard-media:thumbnail-backfill:cursor';

    private const LOCK_CACHE_KEY = 'wallboard-media:thumbnail-backfill:lock';

    public function __construct(
        private readonly WallboardMediaImageProcessor $processor,
        private readonly WallboardMediaQuotaService $quota,
    ) {}

    /** @return array{scanned: int, backfilled: int, unchanged: int, skipped: int, failures: int, locked: bool} */
    public function backfill(?int $requestedBatchSize = null): array
    {
        $batchSize = min(max(
            $requestedBatchSize ?? (int) config('wallboard_media.thumbnail_backfill_batch_size', 10),
            1,
        ), 100);
        $lock = Cache::lock(
            self::LOCK_CACHE_KEY,
            max(30, (int) config('wallboard_media.thumbnail_backfill_lock_seconds', 300)),
        );
        if (! $lock->get()) {
            return $this->emptyResult(true);
        }

        try {
            return $this->runBatch($batchSize);
        } finally {
            $lock->release();
        }
    }

    /** @return array{scanned: int, backfilled: int, unchanged: int, skipped: int, failures: int, locked: bool} */
    private function runBatch(int $batchSize): array
    {
        $cursor = Cache::get(self::CURSOR_CACHE_KEY);
        $cursor = is_string($cursor) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $cursor) === 1
            ? $cursor
            : null;
        $query = fn () => WallboardMediaAsset::query()
            ->where('status', WallboardMediaAsset::STATUS_READY)
            ->where('kind', WallboardMediaAsset::KIND_IMAGE)
            ->orderBy('id');
        $ids = $query()
            ->when($cursor !== null, fn ($builder) => $builder->where('id', '>', $cursor))
            ->limit($batchSize)
            ->pluck('id');
        if ($ids->isEmpty() && $cursor !== null) {
            $ids = $query()->limit($batchSize)->pluck('id');
        }
        if ($ids->isNotEmpty()) {
            Cache::forever(self::CURSOR_CACHE_KEY, (string) $ids->last());
        }

        $result = $this->emptyResult(false);
        foreach ($ids as $id) {
            $result['scanned']++;
            try {
                $outcome = $this->backfillAsset((string) $id);
                $result[$outcome]++;
            } catch (Throwable $exception) {
                $result['failures']++;
                Log::warning('Wallboard media thumbnail backfill failed.', [
                    'asset_id' => (string) $id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /** @return 'backfilled'|'unchanged'|'skipped' */
    private function backfillAsset(string $id): string
    {
        $asset = WallboardMediaAsset::query()->find($id);
        if (! $asset instanceof WallboardMediaAsset
            || $asset->status !== WallboardMediaAsset::STATUS_READY
            || $asset->kind !== WallboardMediaAsset::KIND_IMAGE) {
            return 'skipped';
        }

        $disk = Storage::disk((string) config('wallboard_media.disk', 'local'));
        $thumbnailPath = $this->thumbnailPath($asset);
        $existing = $this->inspectWebp($disk->path($thumbnailPath));
        if ($this->metadataMatches($asset, $thumbnailPath, $existing)) {
            return 'unchanged';
        }

        $sourcePath = $this->sourcePath($asset);
        if ((string) $asset->storage_path !== $sourcePath
            || (string) $asset->mime_type !== 'image/webp') {
            throw new RuntimeException('Stored wallboard image metadata is not canonical.');
        }
        $processed = $this->processor->createThumbnailFromStoredWebp($disk->path($sourcePath));
        try {
            $this->assertSourceMetadata($asset, $processed);
            $accountedBytes = max(0, (int) ($asset->thumbnail_byte_size ?? 0));

            return $this->quota->reserveAdditionalBytes(
                max(0, $processed->byteSize - $accountedBytes),
                fn (): string => $this->persist($id, $processed),
            );
        } finally {
            if (is_file($processed->temporaryPath)) {
                @unlink($processed->temporaryPath);
            }
        }
    }

    /** @return 'backfilled'|'unchanged'|'skipped' */
    private function persist(string $id, WallboardMediaProcessedThumbnail $processed): string
    {
        $installedPath = null;
        try {
            return DB::transaction(function () use ($id, $processed, &$installedPath): string {
                $asset = WallboardMediaAsset::query()->whereKey($id)->lockForUpdate()->first();
                if (! $asset instanceof WallboardMediaAsset
                    || $asset->status !== WallboardMediaAsset::STATUS_READY
                    || $asset->kind !== WallboardMediaAsset::KIND_IMAGE) {
                    return 'skipped';
                }
                $this->assertSourceMetadata($asset, $processed);

                $disk = Storage::disk((string) config('wallboard_media.disk', 'local'));
                $thumbnailPath = $this->thumbnailPath($asset);
                $current = $this->inspectWebp($disk->path($thumbnailPath));
                if ($this->metadataMatches($asset, $thumbnailPath, $current)) {
                    return 'unchanged';
                }

                if ($current === null || $current['sha256'] !== $processed->sha256) {
                    $destination = $disk->path($thumbnailPath);
                    if ($destination === $disk->path($this->sourcePath($asset))) {
                        throw new RuntimeException('Thumbnail destination overlaps the source image.');
                    }
                    $directory = dirname($destination);
                    if ((! is_dir($directory) && ! @mkdir($directory, 0770, true))
                        || ! @rename($processed->temporaryPath, $destination)) {
                        throw new RuntimeException('Generated wallboard thumbnail could not be installed.');
                    }
                    $installedPath = $thumbnailPath;
                    @chmod($directory, 0770);
                    @chmod($destination, 0640);
                    $current = $this->inspectWebp($destination);
                }
                if ($current === null
                    || $current['sha256'] !== $processed->sha256
                    || $current['byte_size'] !== $processed->byteSize) {
                    throw new RuntimeException('Installed wallboard thumbnail could not be verified.');
                }

                $asset->forceFill([
                    'thumbnail_storage_path' => $thumbnailPath,
                    'thumbnail_sha256' => $current['sha256'],
                    'thumbnail_mime_type' => 'image/webp',
                    'thumbnail_byte_size' => $current['byte_size'],
                ])->save();

                return 'backfilled';
            }, 3);
        } catch (Throwable $exception) {
            if (is_string($installedPath)) {
                try {
                    Storage::disk((string) config('wallboard_media.disk', 'local'))->delete($installedPath);
                } catch (Throwable) {
                    // The scheduled orphan cleanup can safely retry this opaque path.
                }
            }

            throw $exception;
        }
    }

    private function assertSourceMetadata(
        WallboardMediaAsset $asset,
        WallboardMediaProcessedThumbnail $processed,
    ): void {
        if ((string) $asset->storage_path !== $this->sourcePath($asset)
            || (string) $asset->mime_type !== 'image/webp'
            || (int) $asset->byte_size !== $processed->sourceByteSize
            || ! hash_equals((string) $asset->sha256, $processed->sourceSha256)
            || (int) $asset->width !== $processed->sourceWidth
            || (int) $asset->height !== $processed->sourceHeight) {
            throw new RuntimeException('Stored wallboard image does not match its database metadata.');
        }
    }

    /** @return array{sha256: string, byte_size: int}|null */
    private function inspectWebp(string $path): ?array
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        $bytes = @filesize($path);
        $dimensions = @getimagesize($path);
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        } catch (Throwable) {
            return null;
        }
        $sha256 = @hash_file('sha256', $path);
        $maxEdge = min(max((int) config('wallboard_media.thumbnail_edge_pixels', 640), 160), 1280);
        if (! is_int($bytes)
            || $bytes < 1
            || $bytes > 16 * 1024 * 1024
            || ! is_array($dimensions)
            || (int) ($dimensions[2] ?? 0) !== IMAGETYPE_WEBP
            || (int) ($dimensions[0] ?? 0) < 1
            || (int) ($dimensions[1] ?? 0) < 1
            || max((int) $dimensions[0], (int) $dimensions[1]) > $maxEdge
            || $mime !== 'image/webp'
            || ! is_string($sha256)
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return null;
        }

        return ['sha256' => $sha256, 'byte_size' => $bytes];
    }

    /** @param array{sha256: string, byte_size: int}|null $actual */
    private function metadataMatches(WallboardMediaAsset $asset, string $path, ?array $actual): bool
    {
        return $actual !== null
            && (string) $asset->thumbnail_storage_path === $path
            && (string) $asset->thumbnail_mime_type === 'image/webp'
            && (int) $asset->thumbnail_byte_size === $actual['byte_size']
            && is_string($asset->thumbnail_sha256)
            && hash_equals((string) $asset->thumbnail_sha256, $actual['sha256']);
    }

    private function sourcePath(WallboardMediaAsset $asset): string
    {
        $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');

        return $root.'/objects/'.(string) $asset->id.'.webp';
    }

    private function thumbnailPath(WallboardMediaAsset $asset): string
    {
        $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');

        return $root.'/objects/'.(string) $asset->id.'.thumbnail.webp';
    }

    /** @return array{scanned: int, backfilled: int, unchanged: int, skipped: int, failures: int, locked: bool} */
    private function emptyResult(bool $locked): array
    {
        return [
            'scanned' => 0,
            'backfilled' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failures' => 0,
            'locked' => $locked,
        ];
    }
}
