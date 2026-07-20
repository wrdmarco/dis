<?php

namespace App\Services;

use App\Models\WallboardMediaAsset;
use App\Models\WallboardMediaPlaylist;
use App\Models\WallboardMediaPlaylistItem;
use App\Support\WallboardMediaNormalizedImage;
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
        private readonly WallboardMediaCoordinationService $coordination,
    ) {}

    /** @return array{scanned: int, normalized: int, backfilled: int, unchanged: int, skipped: int, failures: int, locked: bool} */
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

    /** @return array{scanned: int, normalized: int, backfilled: int, unchanged: int, skipped: int, failures: int, locked: bool} */
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

    /** @return 'normalized'|'backfilled'|'unchanged'|'skipped' */
    private function backfillAsset(string $id): string
    {
        $asset = WallboardMediaAsset::query()->find($id);
        if (! $asset instanceof WallboardMediaAsset
            || $asset->status !== WallboardMediaAsset::STATUS_READY
            || $asset->kind !== WallboardMediaAsset::KIND_IMAGE) {
            return 'skipped';
        }

        $disk = Storage::disk((string) config('wallboard_media.disk', 'local'));
        $needsNormalization = $this->needsNormalization($asset);
        $thumbnailPath = $this->thumbnailPath($asset);
        $existing = $this->inspectWebp($disk->path($thumbnailPath));
        if (! $needsNormalization && $this->metadataMatches($asset, $thumbnailPath, $existing)) {
            return 'unchanged';
        }

        $sourcePath = $this->sourcePath($asset);
        if ((string) $asset->storage_path !== $sourcePath
            || (string) $asset->mime_type !== 'image/webp') {
            throw new RuntimeException('Stored wallboard image metadata is not canonical.');
        }
        if ($needsNormalization) {
            return $this->normalizeAsset($asset, $disk->path($sourcePath));
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

    /** @return 'normalized'|'unchanged'|'skipped' */
    private function normalizeAsset(WallboardMediaAsset $asset, string $sourcePath): string
    {
        $processed = $this->processor->normalizeStoredWebp($sourcePath);
        if (! $processed instanceof WallboardMediaNormalizedImage) {
            throw new RuntimeException('Stored wallboard image dimensions do not match its database metadata.');
        }

        try {
            $this->assertNormalizationSourceMetadata($asset, $processed);
            $accountedBytes = max(0, (int) $asset->byte_size)
                + max(0, (int) ($asset->thumbnail_byte_size ?? 0));
            $replacementBytes = $processed->byteSize + $processed->thumbnailByteSize;

            return $this->quota->reserveAdditionalBytes(
                max(0, $replacementBytes - $accountedBytes),
                fn (): string => $this->persistNormalization(
                    (string) $asset->id,
                    (int) $asset->version,
                    $processed,
                ),
            );
        } finally {
            foreach ([$processed->temporaryPath, $processed->thumbnailTemporaryPath] as $temporaryPath) {
                if (is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
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

    /** @return 'normalized'|'unchanged'|'skipped' */
    private function persistNormalization(
        string $id,
        int $expectedVersion,
        WallboardMediaNormalizedImage $processed,
    ): string {
        $diskName = (string) config('wallboard_media.disk', 'local');
        $disk = Storage::disk($diskName);
        $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');
        $sourceDestination = $disk->path($root.'/objects/'.$id.'.webp');
        $thumbnailDestination = $disk->path($root.'/objects/'.$id.'.thumbnail.webp');
        $prepared = $this->prepareNormalizationFiles(
            $sourceDestination,
            $thumbnailDestination,
            $processed,
        );
        $sourceSwapStarted = false;
        $thumbnailSwapStarted = false;

        try {
            $outcome = DB::transaction(function () use (
                $id,
                $expectedVersion,
                $processed,
                $prepared,
                &$sourceSwapStarted,
                &$thumbnailSwapStarted,
            ): string {
                $this->coordination->lock();
                $playlistIds = WallboardMediaPlaylistItem::query()
                    ->where('media_asset_id', $id)
                    ->orderBy('media_playlist_id')
                    ->pluck('media_playlist_id')
                    ->map(static fn (mixed $playlistId): string => (string) $playlistId)
                    ->all();
                $playlists = WallboardMediaPlaylist::query()
                    ->whereIn('id', $playlistIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $asset = WallboardMediaAsset::query()->whereKey($id)->lockForUpdate()->first();
                if (! $asset instanceof WallboardMediaAsset
                    || $asset->status !== WallboardMediaAsset::STATUS_READY
                    || $asset->kind !== WallboardMediaAsset::KIND_IMAGE) {
                    return 'skipped';
                }
                if (! $this->needsNormalization($asset)) {
                    return 'unchanged';
                }
                if ((int) $asset->version !== $expectedVersion) {
                    return 'skipped';
                }
                $this->assertNormalizationSourceMetadata($asset, $processed);

                $sourcePath = $this->sourcePath($asset);
                $thumbnailPath = $this->thumbnailPath($asset);
                if ((string) $asset->storage_path !== $sourcePath || $sourcePath === $thumbnailPath) {
                    throw new RuntimeException('Stored wallboard image metadata is not canonical.');
                }
                if (! $this->fingerprintMatches($prepared['source_destination'], $prepared['source_fingerprint'])
                    || ! $this->fingerprintMatches(
                        $processed->temporaryPath,
                        $prepared['normalized_fingerprint'],
                    )
                    || ! $this->fingerprintMatches(
                        $processed->thumbnailTemporaryPath,
                        $prepared['normalized_thumbnail_fingerprint'],
                    )
                    || ! $this->optionalFingerprintMatches(
                        $prepared['thumbnail_destination'],
                        $prepared['thumbnail_fingerprint'],
                    )) {
                    return 'skipped';
                }

                try {
                    $sourceSwapStarted = true;
                    $this->swapPreparedFile($processed->temporaryPath, $prepared['source_destination']);
                    $thumbnailSwapStarted = true;
                    $this->swapPreparedFile(
                        $processed->thumbnailTemporaryPath,
                        $prepared['thumbnail_destination'],
                    );
                    if (! $this->fingerprintMatches(
                        $prepared['source_destination'],
                        $prepared['normalized_fingerprint'],
                        false,
                    ) || ! $this->fingerprintMatches(
                        $prepared['thumbnail_destination'],
                        $prepared['normalized_thumbnail_fingerprint'],
                        false,
                    )) {
                        throw new RuntimeException('Normalized wallboard image swap could not be verified.');
                    }
                    $asset->forceFill([
                        'storage_path' => $sourcePath,
                        'sha256' => $processed->sha256,
                        'mime_type' => 'image/webp',
                        'byte_size' => $processed->byteSize,
                        'width' => $processed->width,
                        'height' => $processed->height,
                        'thumbnail_storage_path' => $thumbnailPath,
                        'thumbnail_sha256' => $processed->thumbnailSha256,
                        'thumbnail_mime_type' => 'image/webp',
                        'thumbnail_byte_size' => $processed->thumbnailByteSize,
                        'version' => (int) $asset->version + 1,
                    ])->save();
                    foreach ($playlists as $playlist) {
                        $playlist->forceFill(['version' => (int) $playlist->version + 1])->save();
                    }
                } catch (Throwable $exception) {
                    $rollbackFailures = $this->rollbackStartedNormalizationSwaps(
                        $prepared,
                        $sourceSwapStarted,
                        $thumbnailSwapStarted,
                    );
                    if ($rollbackFailures !== []) {
                        throw new RuntimeException(
                            'Normalized wallboard image could not be rolled back while locked.',
                            0,
                            $exception,
                        );
                    }

                    throw $exception;
                }

                return 'normalized';
            });

            $this->deleteBackup($prepared['source_backup']);
            $this->deleteBackup($prepared['thumbnail_backup']);

            return $outcome;
        } catch (Throwable $exception) {
            $rollbackFailures = $this->rollbackStartedNormalizationSwaps(
                $prepared,
                $sourceSwapStarted,
                $thumbnailSwapStarted,
            );
            if (! $thumbnailSwapStarted) {
                $this->deleteBackup($prepared['thumbnail_backup']);
            }
            if (! $sourceSwapStarted) {
                $this->deleteBackup($prepared['source_backup']);
            }
            if ($rollbackFailures !== []) {
                $rollbackException = $rollbackFailures[0];
                Log::critical('Wallboard media normalization rollback failed.', [
                    'asset_id' => $id,
                    'exception' => $rollbackException::class,
                    'message' => $rollbackException->getMessage(),
                    'failure_count' => count($rollbackFailures),
                ]);

                throw new RuntimeException(
                    'Normalized wallboard image could not be rolled back safely.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    private function assertNormalizationSourceMetadata(
        WallboardMediaAsset $asset,
        WallboardMediaNormalizedImage $processed,
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

    /** @return array{sha256: string, byte_size: int, width: int, height: int}|null */
    private function inspectSourceWebp(string $path): ?array
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
        if (! is_int($bytes)
            || $bytes < 1
            || ! is_array($dimensions)
            || (int) ($dimensions[2] ?? 0) !== IMAGETYPE_WEBP
            || (int) ($dimensions[0] ?? 0) < 1
            || (int) ($dimensions[1] ?? 0) < 1
            || $mime !== 'image/webp'
            || ! is_string($sha256)
            || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return null;
        }

        return [
            'sha256' => $sha256,
            'byte_size' => $bytes,
            'width' => (int) $dimensions[0],
            'height' => (int) $dimensions[1],
        ];
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

    private function needsNormalization(WallboardMediaAsset $asset): bool
    {
        $maximumWidth = max(1, (int) config('wallboard_media.normalized_image_width_pixels', 1920));
        $maximumHeight = max(1, (int) config('wallboard_media.normalized_image_height_pixels', 1080));

        return (int) $asset->width > $maximumWidth || (int) $asset->height > $maximumHeight;
    }

    /**
     * @return array{
     *     source_destination: string,
     *     thumbnail_destination: string,
     *     source_backup: string,
     *     thumbnail_backup: string|null,
     *     source_fingerprint: array{dev: int, ino: int, size: int, mtime: int, ctime: int},
     *     thumbnail_fingerprint: array{dev: int, ino: int, size: int, mtime: int, ctime: int}|null,
     *     normalized_fingerprint: array{dev: int, ino: int, size: int, mtime: int, ctime: int},
     *     normalized_thumbnail_fingerprint: array{dev: int, ino: int, size: int, mtime: int, ctime: int}
     * }
     */
    private function prepareNormalizationFiles(
        string $sourceDestination,
        string $thumbnailDestination,
        WallboardMediaNormalizedImage $processed,
    ): array {
        $sourceBackup = null;
        $thumbnailBackup = null;

        try {
            $this->prepareDestinationDirectory($sourceDestination);
            $this->prepareDestinationDirectory($thumbnailDestination);

            $normalized = $this->inspectSourceWebp($processed->temporaryPath);
            $normalizedThumbnail = $this->inspectWebp($processed->thumbnailTemporaryPath);
            if ($normalized === null
                || $normalized['sha256'] !== $processed->sha256
                || $normalized['byte_size'] !== $processed->byteSize
                || $normalized['width'] !== $processed->width
                || $normalized['height'] !== $processed->height
                || $normalizedThumbnail === null
                || $normalizedThumbnail['sha256'] !== $processed->thumbnailSha256
                || $normalizedThumbnail['byte_size'] !== $processed->thumbnailByteSize) {
                throw new RuntimeException('Prepared wallboard image could not be verified.');
            }
            $normalizedFingerprint = $this->fileFingerprint($processed->temporaryPath);
            $normalizedThumbnailFingerprint = $this->fileFingerprint($processed->thumbnailTemporaryPath);
            if ($normalizedFingerprint === null || $normalizedThumbnailFingerprint === null) {
                throw new RuntimeException('Prepared wallboard image is not a regular file.');
            }

            $sourceFingerprint = $this->fileFingerprint($sourceDestination);
            if ($sourceFingerprint === null) {
                throw new RuntimeException('Stored wallboard image is unavailable.');
            }
            $sourceBackup = $this->backupFile(
                $sourceDestination,
                $processed->sourceSha256,
                $processed->sourceByteSize,
            );
            if (! is_string($sourceBackup)
                || ! $this->fingerprintMatches($sourceDestination, $sourceFingerprint)) {
                throw new RuntimeException('Stored wallboard image changed while it was being prepared.');
            }

            $thumbnailFingerprint = $this->optionalFileFingerprint($thumbnailDestination);
            $thumbnailBackup = $this->backupFile($thumbnailDestination);
            if (($thumbnailFingerprint === null) !== ($thumbnailBackup === null)
                || ! $this->optionalFingerprintMatches($thumbnailDestination, $thumbnailFingerprint)) {
                throw new RuntimeException('Stored wallboard thumbnail changed while it was being prepared.');
            }

            return [
                'source_destination' => $sourceDestination,
                'thumbnail_destination' => $thumbnailDestination,
                'source_backup' => $sourceBackup,
                'thumbnail_backup' => $thumbnailBackup,
                'source_fingerprint' => $sourceFingerprint,
                'thumbnail_fingerprint' => $thumbnailFingerprint,
                'normalized_fingerprint' => $normalizedFingerprint,
                'normalized_thumbnail_fingerprint' => $normalizedThumbnailFingerprint,
            ];
        } catch (Throwable $exception) {
            $this->deleteBackup($sourceBackup);
            $this->deleteBackup($thumbnailBackup);

            throw $exception;
        }
    }

    private function prepareDestinationDirectory(string $destination): void
    {
        if (is_link($destination)) {
            throw new RuntimeException('Wallboard media destination is not a regular file.');
        }
        $directory = dirname($destination);
        if ((! is_dir($directory) && ! @mkdir($directory, 0770, true)) || ! is_writable($directory)) {
            throw new RuntimeException('Wallboard media destination is not writable.');
        }
        @chmod($directory, 0770);
    }

    private function backupFile(string $path, ?string $expectedSha256 = null, ?int $expectedBytes = null): ?string
    {
        if (is_link($path)) {
            throw new RuntimeException('Stored wallboard file is not a regular file.');
        }
        if (! file_exists($path)) {
            return null;
        }
        if (! is_file($path)) {
            throw new RuntimeException('Stored wallboard file is not a regular file.');
        }

        $backup = $this->stagingPath('normalize-backup-');
        if (! @copy($path, $backup)) {
            @unlink($backup);
            throw new RuntimeException('Stored wallboard file could not be backed up.');
        }
        @chmod($backup, 0640);
        $actualBytes = @filesize($backup);
        $actualSha256 = @hash_file('sha256', $backup);
        if (! is_int($actualBytes)
            || $actualBytes < 1
            || ! is_string($actualSha256)
            || ($expectedBytes !== null && $actualBytes !== $expectedBytes)
            || ($expectedSha256 !== null && ! hash_equals($expectedSha256, $actualSha256))) {
            @unlink($backup);
            throw new RuntimeException('Stored wallboard backup could not be verified.');
        }

        return $backup;
    }

    private function swapPreparedFile(string $temporaryPath, string $destination): void
    {
        if (! is_file($temporaryPath) || is_link($temporaryPath) || $temporaryPath === $destination) {
            throw new RuntimeException('Generated wallboard file is not safe to install.');
        }
        if (is_link($destination)) {
            throw new RuntimeException('Wallboard media destination is not a regular file.');
        }
        if (! is_dir(dirname($destination)) || ! is_writable(dirname($destination))) {
            throw new RuntimeException('Wallboard media destination is not writable.');
        }

        if (! @rename($temporaryPath, $destination)) {
            $displaced = $this->stagingPath('normalize-displaced-');
            @unlink($displaced);
            if (! is_file($destination)
                || is_link($destination)
                || ! @rename($destination, $displaced)) {
                throw new RuntimeException('Stored wallboard file could not be prepared for replacement.');
            }
            if (! @rename($temporaryPath, $destination)) {
                @rename($displaced, $destination);
                throw new RuntimeException('Generated wallboard file could not be installed.');
            }
            @unlink($displaced);
        }
    }

    /**
     * @param  array{
     *     source_destination: string,
     *     thumbnail_destination: string,
     *     source_backup: string,
     *     thumbnail_backup: string|null
     * }  $prepared
     * @return list<Throwable>
     */
    private function rollbackStartedNormalizationSwaps(
        array $prepared,
        bool &$sourceSwapStarted,
        bool &$thumbnailSwapStarted,
    ): array {
        $failures = [];
        foreach ([
            [
                $prepared['thumbnail_destination'],
                $prepared['thumbnail_backup'],
                &$thumbnailSwapStarted,
            ],
            [$prepared['source_destination'], $prepared['source_backup'], &$sourceSwapStarted],
        ] as [$destination, $backup, &$swapStarted]) {
            if (! $swapStarted) {
                continue;
            }
            try {
                $this->restoreFile($destination, $backup);
                $swapStarted = false;
            } catch (Throwable $exception) {
                $failures[] = $exception;
            }
        }

        return $failures;
    }

    private function restoreFile(string $destination, ?string $backup): void
    {
        if (! is_string($backup)) {
            if (is_file($destination) && ! @unlink($destination)) {
                throw new RuntimeException('New wallboard file could not be removed during rollback.');
            }

            return;
        }
        if (! is_file($backup) || is_link($backup)) {
            throw new RuntimeException('Wallboard rollback backup is unavailable.');
        }
        if (@rename($backup, $destination)) {
            @chmod($destination, 0640);

            return;
        }
        if (is_file($destination) && ! @unlink($destination)) {
            throw new RuntimeException('New wallboard file could not be displaced during rollback.');
        }
        if (! @rename($backup, $destination)) {
            throw new RuntimeException('Original wallboard file could not be restored.');
        }
        @chmod($destination, 0640);
    }

    /** @return array{dev: int, ino: int, size: int, mtime: int, ctime: int}|null */
    private function fileFingerprint(string $path): ?array
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('Wallboard media file is not a regular file.');
        }
        if (! file_exists($path)) {
            return null;
        }
        if (! is_file($path)) {
            throw new RuntimeException('Wallboard media file is not a regular file.');
        }
        $stat = @lstat($path);
        if (! is_array($stat)
            || ! isset($stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime'])
            || (int) $stat['size'] < 1) {
            throw new RuntimeException('Wallboard media file identity could not be verified.');
        }

        return [
            'dev' => (int) $stat['dev'],
            'ino' => (int) $stat['ino'],
            'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
            'ctime' => (int) $stat['ctime'],
        ];
    }

    /** @return array{dev: int, ino: int, size: int, mtime: int, ctime: int}|null */
    private function optionalFileFingerprint(string $path): ?array
    {
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }

        return $this->fileFingerprint($path);
    }

    /** @param array{dev: int, ino: int, size: int, mtime: int, ctime: int} $expected */
    private function fingerprintMatches(string $path, array $expected, bool $includeCtime = true): bool
    {
        $actual = $this->fileFingerprint($path);
        if ($actual === null) {
            return false;
        }
        if (! $includeCtime) {
            unset($actual['ctime'], $expected['ctime']);
        }

        return $actual === $expected;
    }

    /** @param array{dev: int, ino: int, size: int, mtime: int, ctime: int}|null $expected */
    private function optionalFingerprintMatches(string $path, ?array $expected): bool
    {
        $actual = $this->optionalFileFingerprint($path);

        return $actual === $expected;
    }

    private function deleteBackup(?string $path): void
    {
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
    }

    private function stagingPath(string $prefix): string
    {
        $disk = Storage::disk((string) config('wallboard_media.disk', 'local'));
        $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');
        $directory = $disk->path($root.'/staging');
        if ((! is_dir($directory) && ! @mkdir($directory, 0770, true)) || ! is_writable($directory)) {
            throw new RuntimeException('Wallboard media staging directory is not writable.');
        }
        $path = tempnam($directory, $prefix);
        if (! is_string($path)) {
            throw new RuntimeException('Wallboard media staging file could not be created.');
        }

        return $path;
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

    /** @return array{scanned: int, normalized: int, backfilled: int, unchanged: int, skipped: int, failures: int, locked: bool} */
    private function emptyResult(bool $locked): array
    {
        return [
            'scanned' => 0,
            'normalized' => 0,
            'backfilled' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failures' => 0,
            'locked' => $locked,
        ];
    }
}
