<?php

namespace App\Repositories;

use App\DTO\KnmiPrecipitationRemoteFile;
use App\Exceptions\KnmiPrecipitationImportException;
use App\Services\KnmiPrecipitationConfiguration;
use App\Services\KnmiPrecipitationHdf5Reader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

final class KnmiPrecipitationSnapshotRepository
{
    private const MANIFEST_VERSION = 2;

    private const LEGACY_MANIFEST_VERSION = 1;

    private const MAX_MANIFEST_BYTES = 65_536;

    private const MAX_ATLAS_BYTES = 16_777_216;

    public function __construct(private readonly KnmiPrecipitationConfiguration $configuration) {}

    /**
     * @return array{
     *   version: int,
     *   snapshot_id: string,
     *   reference_time: string,
     *   activated_at: string,
     *   files: array{
     *     radar: array{dataset: string, dataset_version: string, filename: string, relative_path: string, size_bytes: int, sha256: string},
     *     probability: array{dataset: string, dataset_version: string, filename: string, relative_path: string, size_bytes: int, sha256: string}
     *   },
     *   atlas?: array{
     *     filename: string,
     *     relative_path: string,
     *     width: int,
     *     height: int,
     *     columns: int,
     *     rows: int,
     *     frame_width: int,
     *     frame_height: int,
     *     frame_count: int,
     *     size_bytes: int,
     *     sha256: string,
     *     frames: list<array{index: int, valid_at: string, lead_minutes: int}>
     *   },
     *   paths: array{radar: string, probability: string, atlas?: string}
     * }|null
     */
    public function activeSnapshot(): ?array
    {
        $root = $this->existingRoot();
        if ($root === null) {
            return null;
        }
        $manifest = $this->readManifest($root.DIRECTORY_SEPARATOR.'active.json');

        return is_array($manifest) ? $this->resolveSnapshot($root, $manifest) : null;
    }

    /**
     * Resolve the active v2 snapshot or the one retained predecessor. This
     * bounded grace lookup prevents a metadata-to-image activation race from
     * turning an otherwise valid immutable URL into a transient 404.
     *
     * @return array<string, mixed>|null
     */
    public function retainedRadarSnapshot(string $snapshotId): ?array
    {
        if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $snapshotId) !== 1) {
            return null;
        }
        $active = $this->activeSnapshot();
        if (! is_array($active) || ($active['version'] ?? null) !== self::MANIFEST_VERSION) {
            return null;
        }
        if (hash_equals($active['snapshot_id'], $snapshotId)) {
            return $active;
        }
        $root = $this->existingRoot();
        if ($root === null
            || $this->configuration->retainReleases() < 2
            || ! hash_equals((string) $this->retainedPredecessorId($root, $active['snapshot_id']), $snapshotId)) {
            return null;
        }
        $manifestPath = $root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$snapshotId
            .DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = $this->readManifest($manifestPath);
        if (! is_array($manifest)
            || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION
            || ! hash_equals($snapshotId, (string) ($manifest['snapshot_id'] ?? ''))) {
            return null;
        }

        return $this->resolveSnapshot($root, $manifest);
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

        throw new KnmiPrecipitationImportException(
            'storage_unavailable',
            'A unique KNMI precipitation staging directory could not be created.',
        );
    }

    /**
     * @param  array{radar: KnmiPrecipitationRemoteFile, probability: KnmiPrecipitationRemoteFile}  $files
     * @param  array{radar: string, probability: string}  $sha256
     * @param  array{
     *   filename: string,
     *   width: int,
     *   height: int,
     *   columns: int,
     *   rows: int,
     *   frame_width: int,
     *   frame_height: int,
     *   frame_count: int,
     *   size_bytes: int,
     *   sha256: string,
     *   frames: list<array{index: int, valid_at: string, lead_minutes: int}>
     * }  $atlas
     * @return array<string, mixed>
     */
    public function activate(string $stagingDirectory, array $files, array $sha256, array $atlas): array
    {
        $root = $this->ensureRoot();
        $realStage = realpath($stagingDirectory);
        if ($realStage === false
            || is_link($stagingDirectory)
            || ! is_dir($realStage)
            || ! $this->inside($root.DIRECTORY_SEPARATOR.'staging', $realStage)) {
            throw new KnmiPrecipitationImportException('storage_unavailable', 'KNMI precipitation staging path is unsafe.');
        }
        $reference = $files['radar']->referenceTime;
        if (! $reference->equalTo($files['probability']->referenceTime)) {
            throw new KnmiPrecipitationImportException('matching_run_unavailable', 'KNMI precipitation files do not share one run.');
        }
        $validatedAtlas = $this->validatedStagedAtlas($realStage, $reference, $atlas);
        $snapshotId = $reference->format('Ymd\THis\Z').'-'.substr(hash(
            'sha256',
            $sha256['radar'].'|'.$sha256['probability'].'|'.$validatedAtlas['sha256'],
        ), 0, 16);
        $releaseRelative = 'releases/'.$snapshotId;
        $releaseDirectory = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $releaseRelative);
        if (file_exists($releaseDirectory) || is_link($releaseDirectory)) {
            throw new KnmiPrecipitationImportException('storage_unavailable', 'KNMI precipitation release identifier already exists.');
        }

        $manifestFiles = [];
        foreach (['radar', 'probability'] as $kind) {
            $file = $files[$kind];
            $path = $realStage.DIRECTORY_SEPARATOR.$file->filename;
            clearstatcache(true, $path);
            if (! is_file($path)
                || is_link($path)
                || filesize($path) !== $file->sizeBytes
                || preg_match('/\A[a-f0-9]{64}\z/D', $sha256[$kind]) !== 1
                || ! hash_equals($sha256[$kind], (string) @hash_file('sha256', $path))) {
                throw new KnmiPrecipitationImportException('download_integrity_failed', 'KNMI precipitation staging integrity failed.');
            }
            $manifestFiles[$kind] = [
                'dataset' => $file->dataset,
                'dataset_version' => $file->datasetVersion,
                'filename' => $file->filename,
                'relative_path' => $releaseRelative.'/'.$file->filename,
                'size_bytes' => $file->sizeBytes,
                'sha256' => $sha256[$kind],
            ];
        }
        $manifest = [
            'version' => self::MANIFEST_VERSION,
            'snapshot_id' => $snapshotId,
            'reference_time' => $reference->toIso8601String(),
            'activated_at' => CarbonImmutable::now()->utc()->toIso8601String(),
            'files' => $manifestFiles,
            'atlas' => [
                ...$validatedAtlas,
                'relative_path' => $releaseRelative.'/'.$validatedAtlas['filename'],
            ],
        ];
        $encoded = $this->encodeManifest($manifest);
        $manifestPath = $realStage.DIRECTORY_SEPARATOR.'manifest.json';
        if (@file_put_contents($manifestPath, $encoded, LOCK_EX) !== strlen($encoded)) {
            throw new KnmiPrecipitationImportException('storage_unavailable', 'KNMI precipitation manifest could not be staged.');
        }
        @chmod($manifestPath, 0640);

        $releasesRoot = $root.DIRECTORY_SEPARATOR.'releases';
        $this->ensureDirectory($releasesRoot);
        if (! @rename($realStage, $releaseDirectory)) {
            throw new KnmiPrecipitationImportException('storage_unavailable', 'KNMI precipitation release could not be promoted atomically.');
        }

        try {
            File::replace($root.DIRECTORY_SEPARATOR.'active.json', $encoded, 0640);
        } catch (Throwable $exception) {
            $this->deleteTree($releaseDirectory, $releasesRoot);
            throw new KnmiPrecipitationImportException(
                'storage_unavailable',
                'KNMI precipitation active manifest could not be published atomically.',
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
        $version = $manifest['version'] ?? null;
        $expectedKeys = $version === self::LEGACY_MANIFEST_VERSION
            ? ['version', 'snapshot_id', 'reference_time', 'activated_at', 'files']
            : ['version', 'snapshot_id', 'reference_time', 'activated_at', 'files', 'atlas'];
        if (! in_array($version, [self::LEGACY_MANIFEST_VERSION, self::MANIFEST_VERSION], true)
            || ! $this->hasExactKeys($manifest, $expectedKeys)
            || ! is_string($manifest['snapshot_id'] ?? null)
            || preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $manifest['snapshot_id']) !== 1
            || ! is_string($manifest['reference_time'] ?? null)
            || ! is_string($manifest['activated_at'] ?? null)
            || ! $this->validTimestamp($manifest['reference_time'])
            || ! $this->validTimestamp($manifest['activated_at'])
            || ! is_array($manifest['files'] ?? null)
            || ! $this->hasExactKeys($manifest['files'], ['radar', 'probability'])) {
            return false;
        }

        $reference = CarbonImmutable::parse($manifest['reference_time'])->utc();
        $referenceKey = $reference->format('YmdHi');
        if ($reference->second !== 0
            || $reference->minute % 5 !== 0
            || ! str_starts_with($manifest['snapshot_id'], $reference->format('Ymd\THis\Z').'-')) {
            return false;
        }
        $definitions = [
            'radar' => [
                'dataset' => $this->configuration->radarDataset(),
                'version' => $this->configuration->radarVersion(),
                'pattern' => '/\ARAD_NL25_RAC_FM_'.$referenceKey.'\.h5\z/D',
            ],
            'probability' => [
                'dataset' => $this->configuration->probabilityDataset(),
                'version' => $this->configuration->probabilityVersion(),
                'pattern' => '/\AKNMI_PYSTEPS_BLEND_PROB_'.$referenceKey.'\.nc\z/D',
            ],
        ];
        foreach ($definitions as $kind => $definition) {
            $file = $manifest['files'][$kind] ?? null;
            if (! is_array($file)
                || ! $this->hasExactKeys($file, [
                    'dataset',
                    'dataset_version',
                    'filename',
                    'relative_path',
                    'size_bytes',
                    'sha256',
                ])
                || ($file['dataset'] ?? null) !== $definition['dataset']
                || ($file['dataset_version'] ?? null) !== $definition['version']
                || ! is_string($file['filename'] ?? null)
                || preg_match($definition['pattern'], $file['filename']) !== 1
                || ($file['relative_path'] ?? null) !== 'releases/'.$manifest['snapshot_id'].'/'.$file['filename']
                || ! is_int($file['size_bytes'] ?? null)
                || $file['size_bytes'] < $this->configuration->minimumBytes($definition['dataset'])
                || $file['size_bytes'] > $this->configuration->maximumBytes($definition['dataset'])
                || ! is_string($file['sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $file['sha256']) !== 1) {
                return false;
            }
        }

        return $version === self::LEGACY_MANIFEST_VERSION
            || $this->validAtlasManifest($manifest['atlas'] ?? null, $manifest, $reference);
    }

    /**
     * @param  array<string, mixed>  $atlas
     * @return array{
     *   filename: string,
     *   width: int,
     *   height: int,
     *   columns: int,
     *   rows: int,
     *   frame_width: int,
     *   frame_height: int,
     *   frame_count: int,
     *   size_bytes: int,
     *   sha256: string,
     *   frames: list<array{index: int, valid_at: string, lead_minutes: int}>
     * }
     */
    private function validatedStagedAtlas(string $stagingRoot, CarbonImmutable $reference, array $atlas): array
    {
        if (! $this->validAtlasDescriptor($atlas, $reference)) {
            throw new KnmiPrecipitationImportException(
                'local_data_invalid',
                'KNMI precipitation radar atlas metadata is invalid.',
            );
        }
        $path = $stagingRoot.DIRECTORY_SEPARATOR.$atlas['filename'];
        $real = realpath($path);
        clearstatcache(true, $path);
        if ($real === false
            || is_link($path)
            || ! is_file($real)
            || ! is_readable($real)
            || ! hash_equals($this->normalize($stagingRoot), $this->normalize(dirname($real)))
            || filesize($real) !== $atlas['size_bytes']) {
            throw new KnmiPrecipitationImportException(
                'download_integrity_failed',
                'KNMI precipitation radar atlas staging integrity failed.',
            );
        }
        $hash = @hash_file('sha256', $real);
        $dimensions = @getimagesize($real);
        if (! is_string($hash)
            || ! hash_equals($atlas['sha256'], $hash)
            || ! is_array($dimensions)
            || ($dimensions[0] ?? null) !== KnmiPrecipitationHdf5Reader::RADAR_ATLAS_WIDTH
            || ($dimensions[1] ?? null) !== KnmiPrecipitationHdf5Reader::RADAR_ATLAS_HEIGHT
            || ($dimensions[2] ?? null) !== IMAGETYPE_PNG) {
            throw new KnmiPrecipitationImportException(
                'download_integrity_failed',
                'KNMI precipitation radar atlas file is invalid.',
            );
        }

        return $atlas;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validAtlasManifest(mixed $atlas, array $manifest, CarbonImmutable $reference): bool
    {
        if (! is_array($atlas)
            || ! $this->hasExactKeys($atlas, [
                'filename',
                'relative_path',
                'width',
                'height',
                'columns',
                'rows',
                'frame_width',
                'frame_height',
                'frame_count',
                'size_bytes',
                'sha256',
                'frames',
            ])) {
            return false;
        }
        $descriptor = $atlas;
        unset($descriptor['relative_path']);

        return $atlas['relative_path'] === 'releases/'.$manifest['snapshot_id'].'/'.KnmiPrecipitationHdf5Reader::RADAR_ATLAS_FILENAME
            && $this->validAtlasDescriptor($descriptor, $reference);
    }

    /** @param array<string, mixed> $atlas */
    private function validAtlasDescriptor(array $atlas, CarbonImmutable $reference): bool
    {
        if (! $this->hasExactKeys($atlas, [
            'filename',
            'width',
            'height',
            'columns',
            'rows',
            'frame_width',
            'frame_height',
            'frame_count',
            'size_bytes',
            'sha256',
            'frames',
        ])
            || ($atlas['filename'] ?? null) !== KnmiPrecipitationHdf5Reader::RADAR_ATLAS_FILENAME
            || ($atlas['width'] ?? null) !== KnmiPrecipitationHdf5Reader::RADAR_ATLAS_WIDTH
            || ($atlas['height'] ?? null) !== KnmiPrecipitationHdf5Reader::RADAR_ATLAS_HEIGHT
            || ($atlas['columns'] ?? null) !== KnmiPrecipitationHdf5Reader::RADAR_ATLAS_COLUMNS
            || ($atlas['rows'] ?? null) !== KnmiPrecipitationHdf5Reader::RADAR_ATLAS_ROWS
            || ($atlas['frame_width'] ?? null) !== KnmiPrecipitationHdf5Reader::RADAR_ATLAS_FRAME_WIDTH
            || ($atlas['frame_height'] ?? null) !== KnmiPrecipitationHdf5Reader::RADAR_ATLAS_FRAME_HEIGHT
            || ($atlas['frame_count'] ?? null) !== 25
            || ! is_int($atlas['size_bytes'] ?? null)
            || $atlas['size_bytes'] < 64
            || $atlas['size_bytes'] > self::MAX_ATLAS_BYTES
            || ! is_string($atlas['sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $atlas['sha256']) !== 1
            || ! is_array($atlas['frames'] ?? null)
            || ! array_is_list($atlas['frames'])
            || count($atlas['frames']) !== 25) {
            return false;
        }
        foreach ($atlas['frames'] as $index => $frame) {
            if (! is_array($frame)
                || ! $this->hasExactKeys($frame, ['index', 'valid_at', 'lead_minutes'])
                || ($frame['index'] ?? null) !== $index
                || ($frame['lead_minutes'] ?? null) !== $index * 5
                || ! is_string($frame['valid_at'] ?? null)
                || ! hash_equals(
                    $reference->addMinutes($index * 5)->toIso8601String(),
                    $frame['valid_at'],
                )) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $path): ?array
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path) || ! is_readable($path)) {
            return null;
        }
        $size = filesize($path);
        if (! is_int($size) || $size < 2 || $size > self::MAX_MANIFEST_BYTES) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (! is_string($raw) || strlen($raw) !== $size) {
            return null;
        }
        try {
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);

            return is_array($manifest) && $this->validManifest($manifest) ? $manifest : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function resolveSnapshot(string $root, array $manifest): ?array
    {
        $releasePath = $root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$manifest['snapshot_id'];
        $releaseRoot = realpath($releasePath);
        $releasesRoot = realpath($root.DIRECTORY_SEPARATOR.'releases');
        if ($releaseRoot === false
            || $releasesRoot === false
            || is_link($releasePath)
            || ! is_dir($releaseRoot)
            || ! hash_equals($this->normalize($releasesRoot), $this->normalize(dirname($releaseRoot)))) {
            return null;
        }
        $paths = [];
        foreach (['radar', 'probability'] as $kind) {
            $file = $manifest['files'][$kind];
            $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['relative_path']);
            $real = realpath($candidate);
            clearstatcache(true, $candidate);
            if ($real === false
                || is_link($candidate)
                || ! is_file($real)
                || ! is_readable($real)
                || ! hash_equals($this->normalize($releaseRoot), $this->normalize(dirname($real)))
                || filesize($real) !== $file['size_bytes']) {
                return null;
            }
            $stat = @stat($real);
            if (! is_array($stat)
                || ! is_int($stat['dev'] ?? null)
                || ! is_int($stat['ino'] ?? null)
                || ! is_int($stat['mtime'] ?? null)
                || ! is_int($stat['ctime'] ?? null)
                || ! is_int($stat['size'] ?? null)) {
                return null;
            }
            $integrityKey = 'knmi:precipitation:integrity:'
                .$manifest['snapshot_id'].':'.$kind.':'.implode(':', [
                    $stat['dev'],
                    $stat['ino'],
                    $stat['mtime'],
                    $stat['ctime'],
                    $stat['size'],
                ]);
            $validHash = Cache::remember(
                $integrityKey,
                $this->configuration->integrityCacheSeconds(),
                static function () use ($real, $file): bool {
                    $actual = @hash_file('sha256', $real);

                    return is_string($actual) && hash_equals($file['sha256'], $actual);
                },
            );
            if ($validHash !== true) {
                return null;
            }
            $paths[$kind] = $real;
        }

        if (($manifest['version'] ?? null) === self::MANIFEST_VERSION) {
            $atlas = $manifest['atlas'];
            $atlasCandidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $atlas['relative_path']);
            $atlasReal = realpath($atlasCandidate);
            clearstatcache(true, $atlasCandidate);
            if ($atlasReal === false
                || is_link($atlasCandidate)
                || ! is_file($atlasReal)
                || ! is_readable($atlasReal)
                || ! hash_equals($this->normalize($releaseRoot), $this->normalize(dirname($atlasReal)))
                || filesize($atlasReal) !== $atlas['size_bytes']) {
                return null;
            }
            $atlasHash = @hash_file('sha256', $atlasReal);
            $atlasDimensions = @getimagesize($atlasReal);
            if (! is_string($atlasHash)
                || ! hash_equals($atlas['sha256'], $atlasHash)
                || ! is_array($atlasDimensions)
                || ($atlasDimensions[0] ?? null) !== $atlas['width']
                || ($atlasDimensions[1] ?? null) !== $atlas['height']
                || ($atlasDimensions[2] ?? null) !== IMAGETYPE_PNG) {
                return null;
            }
            $paths['atlas'] = $atlasReal;
        }

        return [...$manifest, 'paths' => $paths];
    }

    private function retainedPredecessorId(string $root, string $activeSnapshotId): ?string
    {
        $releasesRoot = realpath($root.DIRECTORY_SEPARATOR.'releases');
        if ($releasesRoot === false || ! is_dir($releasesRoot)) {
            return null;
        }
        $snapshotIds = [];
        foreach (glob($releasesRoot.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $entry) {
            $name = basename($entry);
            $real = realpath($entry);
            if ($name !== $activeSnapshotId
                && preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $name) === 1
                && $real !== false
                && ! is_link($entry)
                && hash_equals($this->normalize($releasesRoot), $this->normalize(dirname($real)))) {
                $snapshotIds[] = $name;
            }
        }
        rsort($snapshotIds, SORT_STRING);

        return $snapshotIds[0] ?? null;
    }

    private function ensureRoot(): string
    {
        $configured = $this->configuration->storageRoot();
        $this->ensureDirectory($configured);
        $root = realpath($configured);
        if ($root === false || is_link($configured) || ! is_dir($root) || ! is_writable($root)) {
            throw new KnmiPrecipitationImportException('storage_unavailable', 'KNMI precipitation storage root is unsafe.');
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
            throw new KnmiPrecipitationImportException('storage_unavailable', 'KNMI precipitation directory could not be created.');
        }
        if (is_link($path)) {
            throw new KnmiPrecipitationImportException('storage_unavailable', 'KNMI precipitation directory cannot be a symlink.');
        }
        @chmod($path, 0770);
    }

    private function prune(string $activeSnapshotId): void
    {
        $root = $this->ensureRoot().DIRECTORY_SEPARATOR.'releases';
        $entries = glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [];
        $snapshots = [];
        foreach ($entries as $entry) {
            $name = basename($entry);
            if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $name) === 1
                && ! is_link($entry)) {
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
        $cutoff = time() - 3600;
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

    /** @param array<string, mixed> $manifest */
    private function encodeManifest(array $manifest): string
    {
        try {
            $encoded = json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            )."\n";
        } catch (JsonException $exception) {
            throw new KnmiPrecipitationImportException('storage_unavailable', 'KNMI precipitation manifest encoding failed.', $exception);
        }
        if (strlen($encoded) > self::MAX_MANIFEST_BYTES) {
            throw new KnmiPrecipitationImportException('storage_unavailable', 'KNMI precipitation manifest exceeded its size limit.');
        }

        return $encoded;
    }
}
