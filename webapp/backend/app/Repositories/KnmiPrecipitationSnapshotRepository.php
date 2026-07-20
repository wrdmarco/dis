<?php

namespace App\Repositories;

use App\DTO\KnmiPrecipitationRemoteFile;
use App\Exceptions\KnmiPrecipitationImportException;
use App\Services\KnmiPrecipitationConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

final class KnmiPrecipitationSnapshotRepository
{
    private const MANIFEST_VERSION = 1;

    private const MAX_MANIFEST_BYTES = 16_384;

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
     *   paths: array{radar: string, probability: string}
     * }|null
     */
    public function activeSnapshot(): ?array
    {
        $root = $this->existingRoot();
        if ($root === null) {
            return null;
        }
        $path = $root.DIRECTORY_SEPARATOR.'active.json';
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
        } catch (JsonException) {
            return null;
        }
        try {
            $manifestValid = is_array($manifest) && $this->validManifest($manifest);
        } catch (Throwable) {
            return null;
        }
        if (! $manifestValid) {
            return null;
        }

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

        return [...$manifest, 'paths' => $paths];
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
     * @return array<string, mixed>
     */
    public function activate(string $stagingDirectory, array $files, array $sha256): array
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
        $snapshotId = $reference->format('Ymd\THis\Z').'-'.substr(hash(
            'sha256',
            $sha256['radar'].'|'.$sha256['probability'],
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
        if (! $this->hasExactKeys($manifest, ['version', 'snapshot_id', 'reference_time', 'activated_at', 'files'])
            || ($manifest['version'] ?? null) !== self::MANIFEST_VERSION
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

        return true;
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
