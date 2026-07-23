<?php

namespace App\Repositories;

use App\Services\EumetsatLightningConfiguration;
use App\Services\EumetsatLightningImportException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

final class EumetsatLightningSnapshotRepository
{
    private const MANIFEST_VERSION = 1;

    private const MAX_MANIFEST_BYTES = 32_768;

    private const INTEGRITY_CACHE_SECONDS = 300;

    public function __construct(private readonly EumetsatLightningConfiguration $configuration) {}

    /**
     * @return array{
     *   version: int,
     *   snapshot_id: string,
     *   latest_frame_at: string,
     *   activated_at: string,
     *   frames: list<string>,
     *   atlas: array{filename: string, relative_path: string, size_bytes: int, sha256: string, columns: int, rows: int, frame_width: int, frame_height: int, width: int, height: int},
     *   source: array{name: string, url: string, layer: string},
     *   license: array{name: string, url: string},
     *   path: string
     * }|null
     */
    public function activeSnapshot(): ?array
    {
        try {
            $root = $this->existingRoot();

            return $root === null ? null : $this->activeSnapshotFromRoot($root);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve only the active snapshot or the one exact previous release kept
     * by the two-release retention policy. This closes the short race where a
     * client receives metadata just before a successor is activated.
     *
     * @return array<string, mixed>|null
     */
    public function retainedSnapshot(string $snapshotId): ?array
    {
        if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $snapshotId) !== 1) {
            return null;
        }
        try {
            $root = $this->existingRoot();
            if ($root === null) {
                return null;
            }
            $active = $this->activeSnapshotFromRoot($root);
            if ($active === null) {
                return null;
            }
            if (hash_equals($active['snapshot_id'], $snapshotId)) {
                return $active;
            }
            $previousId = $this->retainedPreviousSnapshotId($root, $active['snapshot_id']);
            if ($previousId === null || ! hash_equals($previousId, $snapshotId)) {
                return null;
            }
            $releaseManifestPath = $root.DIRECTORY_SEPARATOR.'releases'
                .DIRECTORY_SEPARATOR.$previousId.DIRECTORY_SEPARATOR.'manifest.json';
            $raw = $this->readBoundedFile($releaseManifestPath, self::MAX_MANIFEST_BYTES);
            $manifest = $this->decodeManifest($raw);
            if ($manifest === null || ! hash_equals($previousId, $manifest['snapshot_id'])) {
                return null;
            }

            return $this->validatedRelease($root, $manifest, $raw);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function activeSnapshotFromRoot(string $root): ?array
    {
        $raw = $this->readBoundedFile(
            $root.DIRECTORY_SEPARATOR.'active.json',
            self::MAX_MANIFEST_BYTES,
        );
        $manifest = $this->decodeManifest($raw);
        if ($manifest === null) {
            return null;
        }

        return $this->validatedRelease($root, $manifest, $raw);
    }

    /** @return array<string, mixed>|null */
    private function decodeManifest(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        try {
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($manifest) || ! $this->validManifest($manifest)) {
            return null;
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function validatedRelease(string $root, array $manifest, string $expectedManifest): ?array
    {
        $snapshotId = $manifest['snapshot_id'];
        if (! is_string($snapshotId)) {
            return null;
        }

        $releasesRoot = realpath($root.DIRECTORY_SEPARATOR.'releases');
        $releasePath = $root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$snapshotId;
        $releaseRoot = realpath($releasePath);
        if ($releasesRoot === false
            || $releaseRoot === false
            || is_link($releasePath)
            || ! is_dir($releaseRoot)
            || ! hash_equals($this->normalize($releasesRoot), $this->normalize(dirname($releaseRoot)))) {
            return null;
        }
        $releaseManifest = $this->readBoundedFile(
            $releaseRoot.DIRECTORY_SEPARATOR.'manifest.json',
            self::MAX_MANIFEST_BYTES,
        );
        if (! is_string($releaseManifest) || ! hash_equals($expectedManifest, $releaseManifest)) {
            return null;
        }

        $candidate = $root.DIRECTORY_SEPARATOR.str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $manifest['atlas']['relative_path'],
        );
        $realAtlas = realpath($candidate);
        clearstatcache(true, $candidate);
        if ($realAtlas === false
            || is_link($candidate)
            || ! is_file($realAtlas)
            || ! is_readable($realAtlas)
            || ! hash_equals($this->normalize($releaseRoot), $this->normalize(dirname($realAtlas)))) {
            return null;
        }
        $atlasStat = @stat($realAtlas);
        if (! is_array($atlasStat)
            || ! is_int($atlasStat['dev'] ?? null)
            || ! is_int($atlasStat['ino'] ?? null)
            || ! is_int($atlasStat['mtime'] ?? null)
            || ! is_int($atlasStat['ctime'] ?? null)
            || ! is_int($atlasStat['size'] ?? null)
            || $atlasStat['size'] !== $manifest['atlas']['size_bytes']) {
            return null;
        }
        $integrityKey = 'eumetsat:lightning:integrity:'
            .$manifest['snapshot_id'].':atlas:'.implode(':', [
                $atlasStat['dev'],
                $atlasStat['ino'],
                $atlasStat['mtime'],
                $atlasStat['ctime'],
                $atlasStat['size'],
            ]);
        $validAtlas = Cache::remember(
            $integrityKey,
            self::INTEGRITY_CACHE_SECONDS,
            function () use ($realAtlas, $manifest): bool {
                $signature = @file_get_contents($realAtlas, false, null, 0, 8);
                $dimensions = @getimagesize($realAtlas);
                $sha256 = @hash_file('sha256', $realAtlas);

                return $signature === "\x89PNG\r\n\x1a\n"
                    && is_array($dimensions)
                    && ($dimensions[0] ?? null) === $this->configuration->atlasWidth()
                    && ($dimensions[1] ?? null) === $this->configuration->atlasHeight()
                    && ($dimensions[2] ?? null) === IMAGETYPE_PNG
                    && is_string($sha256)
                    && hash_equals($manifest['atlas']['sha256'], $sha256);
            },
        );
        if ($validAtlas !== true) {
            return null;
        }

        return [...$manifest, 'path' => $realAtlas];
    }

    public function createStagingDirectory(): string
    {
        $root = $this->ensureRoot();
        $stagingRoot = $root.DIRECTORY_SEPARATOR.'staging';
        $this->ensureDirectory($stagingRoot);
        $this->pruneStaging($stagingRoot);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $stagingRoot.DIRECTORY_SEPARATOR.str()->lower((string) str()->ulid());
            if (@mkdir($path, 0770)) {
                return $path;
            }
        }

        throw new EumetsatLightningImportException(
            'storage_unavailable',
            'A unique EUMETSAT lightning staging directory could not be created.',
        );
    }

    /**
     * @param  list<CarbonImmutable>  $frameTimes
     * @param  array{path: string, size_bytes: int, sha256: string, width: int, height: int}  $atlas
     * @return array<string, mixed>
     */
    public function activate(string $stagingDirectory, array $frameTimes, array $atlas): array
    {
        $root = $this->ensureRoot();
        $realStage = realpath($stagingDirectory);
        if ($realStage === false
            || is_link($stagingDirectory)
            || ! is_dir($realStage)
            || ! $this->inside($root.DIRECTORY_SEPARATOR.'staging', $realStage)) {
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'The EUMETSAT lightning staging path is unsafe.',
            );
        }
        $this->validateFrameTimes($frameTimes);
        $atlasPath = realpath($atlas['path']);
        if ($atlasPath === false
            || is_link($atlas['path'])
            || ! is_file($atlasPath)
            || ! is_readable($atlasPath)
            || ! hash_equals($this->normalize($realStage), $this->normalize(dirname($atlasPath)))
            || basename($atlasPath) !== 'lightning-atlas.png'
            || filesize($atlasPath) !== $atlas['size_bytes']
            || $atlas['size_bytes'] < 67
            || $atlas['size_bytes'] > $this->configuration->maximumAtlasBytes()
            || $atlas['width'] !== $this->configuration->atlasWidth()
            || $atlas['height'] !== $this->configuration->atlasHeight()
            || preg_match('/\A[a-f0-9]{64}\z/D', $atlas['sha256']) !== 1
            || @file_get_contents($atlasPath, false, null, 0, 8) !== "\x89PNG\r\n\x1a\n"
            || ! $this->hasExpectedAtlasDimensions($atlasPath)
            || ! hash_equals($atlas['sha256'], (string) @hash_file('sha256', $atlasPath))) {
            throw new EumetsatLightningImportException(
                'atlas_integrity_failed',
                'The staged EUMETSAT lightning atlas failed integrity validation.',
            );
        }

        $latest = end($frameTimes);
        if (! $latest instanceof CarbonImmutable) {
            throw new EumetsatLightningImportException(
                'frame_set_incomplete',
                'The EUMETSAT lightning frame set has no latest frame.',
            );
        }
        $frames = array_map(
            static fn (CarbonImmutable $time): string => $time->utc()->toIso8601String(),
            $frameTimes,
        );
        $snapshotId = $latest->utc()->format('Ymd\THis\Z').'-'.substr(hash(
            'sha256',
            $atlas['sha256'].'|'.implode('|', $frames),
        ), 0, 16);
        $releaseRelative = 'releases/'.$snapshotId;
        $releaseDirectory = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $releaseRelative);
        if (file_exists($releaseDirectory) || is_link($releaseDirectory)) {
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'The EUMETSAT lightning release identifier already exists.',
            );
        }
        $manifest = [
            'version' => self::MANIFEST_VERSION,
            'snapshot_id' => $snapshotId,
            'latest_frame_at' => $latest->utc()->toIso8601String(),
            'activated_at' => CarbonImmutable::now()->utc()->toIso8601String(),
            'frames' => $frames,
            'atlas' => [
                'filename' => 'lightning-atlas.png',
                'relative_path' => $releaseRelative.'/lightning-atlas.png',
                'size_bytes' => $atlas['size_bytes'],
                'sha256' => $atlas['sha256'],
                'columns' => $this->configuration->atlasColumns(),
                'rows' => $this->configuration->atlasRows(),
                'frame_width' => $this->configuration->frameWidth(),
                'frame_height' => $this->configuration->frameHeight(),
                'width' => $this->configuration->atlasWidth(),
                'height' => $this->configuration->atlasHeight(),
            ],
            'source' => $this->configuration->source(),
            'license' => $this->configuration->license(),
        ];
        $encoded = $this->encodeManifest($manifest);
        $manifestPath = $realStage.DIRECTORY_SEPARATOR.'manifest.json';
        if (@file_put_contents($manifestPath, $encoded, LOCK_EX) !== strlen($encoded)) {
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'The EUMETSAT lightning manifest could not be staged.',
            );
        }
        @chmod($manifestPath, 0640);

        $releasesRoot = $root.DIRECTORY_SEPARATOR.'releases';
        $this->ensureDirectory($releasesRoot);
        if (! @rename($realStage, $releaseDirectory)) {
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'The EUMETSAT lightning release could not be promoted atomically.',
            );
        }
        try {
            File::replace($root.DIRECTORY_SEPARATOR.'active.json', $encoded, 0640);
        } catch (Throwable $exception) {
            $this->deleteTree($releaseDirectory, $releasesRoot);
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'The EUMETSAT lightning active manifest could not be published atomically.',
                $exception,
            );
        }
        $this->prune($snapshotId);

        return $manifest;
    }

    public function discardStaging(string $path): void
    {
        $root = $this->existingRoot();
        if ($root === null) {
            return;
        }
        $stagingRoot = $root.DIRECTORY_SEPARATOR.'staging';
        $real = realpath($path);
        if ($real !== false && is_dir($real) && ! is_link($path) && $this->inside($stagingRoot, $real)) {
            $this->deleteTree($real, $stagingRoot);
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validManifest(array $manifest): bool
    {
        if (! $this->hasExactKeys($manifest, [
            'version',
            'snapshot_id',
            'latest_frame_at',
            'activated_at',
            'frames',
            'atlas',
            'source',
            'license',
        ])
            || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION
            || ! is_string($manifest['snapshot_id'] ?? null)
            || preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $manifest['snapshot_id']) !== 1
            || ! is_string($manifest['latest_frame_at'] ?? null)
            || ! is_string($manifest['activated_at'] ?? null)
            || ! $this->validTimestamp($manifest['latest_frame_at'])
            || ! $this->validTimestamp($manifest['activated_at'])
            || ! is_array($manifest['frames'] ?? null)
            || ! array_is_list($manifest['frames'])
            || count($manifest['frames']) !== $this->configuration->frameCount()
            || ! is_array($manifest['atlas'] ?? null)
            || ! is_array($manifest['source'] ?? null)
            || ! is_array($manifest['license'] ?? null)) {
            return false;
        }
        $frameTimes = [];
        foreach ($manifest['frames'] as $frame) {
            if (! is_string($frame) || ! $this->validTimestamp($frame)) {
                return false;
            }
            $frameTimes[] = CarbonImmutable::parse($frame)->utc();
        }
        try {
            $this->validateFrameTimes($frameTimes);
        } catch (Throwable) {
            return false;
        }
        $latest = end($frameTimes);
        if (! $latest instanceof CarbonImmutable
            || ! $latest->equalTo(CarbonImmutable::parse($manifest['latest_frame_at'])->utc())
            || ! str_starts_with($manifest['snapshot_id'], $latest->format('Ymd\THis\Z').'-')) {
            return false;
        }

        $atlas = $manifest['atlas'];
        if (! $this->hasExactKeys($atlas, [
            'filename',
            'relative_path',
            'size_bytes',
            'sha256',
            'columns',
            'rows',
            'frame_width',
            'frame_height',
            'width',
            'height',
        ])
            || ($atlas['filename'] ?? null) !== 'lightning-atlas.png'
            || ($atlas['relative_path'] ?? null) !== 'releases/'.$manifest['snapshot_id'].'/lightning-atlas.png'
            || ! is_int($atlas['size_bytes'] ?? null)
            || $atlas['size_bytes'] < 67
            || $atlas['size_bytes'] > $this->configuration->maximumAtlasBytes()
            || ! is_string($atlas['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $atlas['sha256']) !== 1
            || ($atlas['columns'] ?? null) !== $this->configuration->atlasColumns()
            || ($atlas['rows'] ?? null) !== $this->configuration->atlasRows()
            || ($atlas['frame_width'] ?? null) !== $this->configuration->frameWidth()
            || ($atlas['frame_height'] ?? null) !== $this->configuration->frameHeight()
            || ($atlas['width'] ?? null) !== $this->configuration->atlasWidth()
            || ($atlas['height'] ?? null) !== $this->configuration->atlasHeight()
            || $manifest['source'] !== $this->configuration->source()
            || $manifest['license'] !== $this->configuration->license()) {
            return false;
        }
        $expectedSuffix = substr(hash(
            'sha256',
            $atlas['sha256'].'|'.implode('|', $manifest['frames']),
        ), 0, 16);

        return hash_equals($latest->format('Ymd\THis\Z').'-'.$expectedSuffix, $manifest['snapshot_id']);
    }

    /** @param list<CarbonImmutable> $frameTimes */
    private function validateFrameTimes(array $frameTimes): void
    {
        if (count($frameTimes) !== $this->configuration->frameCount()) {
            throw new EumetsatLightningImportException(
                'frame_set_incomplete',
                'The EUMETSAT lightning manifest does not contain seven frames.',
            );
        }
        $stepSeconds = $this->configuration->intervalMinutes() * 60;
        foreach ($frameTimes as $index => $time) {
            if (! $time instanceof CarbonImmutable
                || $time->second !== 0
                || $time->minute % $this->configuration->intervalMinutes() !== 0
                || ($index > 0
                    && $time->getTimestamp() - $frameTimes[$index - 1]->getTimestamp() !== $stepSeconds)) {
                throw new EumetsatLightningImportException(
                    'frame_set_incomplete',
                    'The EUMETSAT lightning manifest timeline is invalid.',
                );
            }
        }
    }

    private function ensureRoot(): string
    {
        $configured = $this->configuration->storageRoot();
        $this->ensureDirectory($configured);
        $root = realpath($configured);
        if ($root === false || is_link($configured) || ! is_dir($root) || ! is_writable($root)) {
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'The EUMETSAT lightning storage root is unsafe.',
            );
        }

        return $root;
    }

    private function existingRoot(): ?string
    {
        try {
            $configured = $this->configuration->storageRoot();
        } catch (Throwable) {
            return null;
        }
        $root = realpath($configured);

        return $root !== false && ! is_link($configured) && is_dir($root) ? $root : null;
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! @mkdir($path, 0770, true) && ! is_dir($path)) {
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'An EUMETSAT lightning directory could not be created.',
            );
        }
        if (is_link($path)) {
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'An EUMETSAT lightning directory cannot be a symlink.',
            );
        }
        @chmod($path, 0770);
    }

    private function retainedPreviousSnapshotId(string $root, string $activeSnapshotId): ?string
    {
        if ($this->configuration->retainReleases() !== 2) {
            return null;
        }
        $releasesRoot = realpath($root.DIRECTORY_SEPARATOR.'releases');
        if ($releasesRoot === false || ! is_dir($releasesRoot)) {
            return null;
        }
        $snapshotIds = [];
        foreach (glob($releasesRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $entry) {
            $name = basename($entry);
            $real = realpath($entry);
            if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $name) === 1
                && $real !== false
                && ! is_link($entry)
                && is_dir($real)
                && hash_equals($this->normalize($releasesRoot), $this->normalize(dirname($real)))) {
                $snapshotIds[] = $name;
            }
        }
        rsort($snapshotIds, SORT_STRING);
        foreach ($snapshotIds as $candidate) {
            if (! hash_equals($activeSnapshotId, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function prune(string $activeSnapshotId): void
    {
        $root = $this->ensureRoot().DIRECTORY_SEPARATOR.'releases';
        $entries = glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        $snapshots = [];
        foreach ($entries as $entry) {
            $name = basename($entry);
            if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $name) === 1 && ! is_link($entry)) {
                $snapshots[$name] = $entry;
            }
        }
        krsort($snapshots, SORT_STRING);
        $keep = [$activeSnapshotId];
        foreach (array_keys($snapshots) as $candidate) {
            if ($candidate !== $activeSnapshotId && count($keep) < $this->configuration->retainReleases()) {
                $keep[] = $candidate;
            }
        }
        foreach ($snapshots as $name => $entry) {
            if (! in_array($name, $keep, true)) {
                $this->deleteTree($entry, $root);
            }
        }
    }

    private function pruneStaging(string $stagingRoot): void
    {
        $cutoff = time() - 1800;
        foreach (glob($stagingRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $entry) {
            $mtime = @filemtime($entry);
            if (is_int($mtime) && $mtime < $cutoff) {
                $this->deleteTree($entry, $stagingRoot);
            }
        }
    }

    private function deleteTree(string $path, string $expectedParent): void
    {
        $real = realpath($path);
        $parent = realpath($expectedParent);
        if ($real === false
            || $parent === false
            || is_link($path)
            || ! is_dir($real)
            || ! hash_equals($this->normalize($parent), $this->normalize(dirname($real)))) {
            return;
        }
        try {
            $iterator = new \FilesystemIterator($real, \FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $entry) {
                $entryPath = $entry->getPathname();
                if ($entry->isLink() || $entry->isFile()) {
                    @unlink($entryPath);
                } elseif ($entry->isDir()) {
                    $this->deleteTree($entryPath, $real);
                }
            }
        } catch (Throwable) {
            return;
        }
        @rmdir($real);
    }

    private function readBoundedFile(string $path, int $maximumBytes): ?string
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            return null;
        }
        $size = @filesize($path);
        if (! is_int($size) || $size < 2 || $size > $maximumBytes) {
            return null;
        }
        $raw = @file_get_contents($path);

        return is_string($raw) && strlen($raw) === $size ? $raw : null;
    }

    private function hasExpectedAtlasDimensions(string $path): bool
    {
        $dimensions = @getimagesize($path);

        return is_array($dimensions)
            && ($dimensions[0] ?? null) === $this->configuration->atlasWidth()
            && ($dimensions[1] ?? null) === $this->configuration->atlasHeight()
            && ($dimensions[2] ?? null) === IMAGETYPE_PNG;
    }

    /** @param array<string, mixed> $manifest */
    private function encodeManifest(array $manifest): string
    {
        try {
            $encoded = json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            )."\n";
        } catch (JsonException $exception) {
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'The EUMETSAT lightning manifest could not be encoded.',
                $exception,
            );
        }
        if (strlen($encoded) > self::MAX_MANIFEST_BYTES) {
            throw new EumetsatLightningImportException(
                'storage_unavailable',
                'The EUMETSAT lightning manifest exceeded its size limit.',
            );
        }

        return $encoded;
    }

    /** @param array<string, mixed> $value */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function inside(string $root, string $path): bool
    {
        $root = rtrim($this->normalize($root), '/');
        $path = $this->normalize($path);

        return str_starts_with($path, $root.'/');
    }

    private function normalize(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private function validTimestamp(string $value): bool
    {
        if (strlen($value) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            return false;
        }
        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
