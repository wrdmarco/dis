<?php

namespace App\Services;

use App\DTO\KnmiOpenDataArchive;
use App\Exceptions\KnmiForecastImportException;
use App\Models\KnmiForecastOperation;
use App\Models\KnmiForecastSnapshot;
use App\Models\User;
use App\Repositories\KnmiForecastSnapshotRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class KnmiForecastImportService
{
    private const DISK_RESERVE_BYTES = 268_435_456;

    public function __construct(
        private readonly KnmiOpenDataClient $client,
        private readonly KnmiForecastTarExtractor $extractor,
        private readonly KnmiForecastSemanticValidator $semanticValidator,
        private readonly KnmiOpenDataConfiguration $configuration,
        private readonly KnmiForecastSnapshotRepository $snapshots,
        private readonly AuditService $audit,
    ) {}

    public function run(string $operationId): void
    {
        $existingOperation = KnmiForecastOperation::query()->find($operationId);
        if ($existingOperation === null) {
            return;
        }
        try {
            $operation = $this->markRunning($operationId);
        } catch (Throwable $exception) {
            $this->fail($existingOperation, $exception);

            return;
        }
        if ($operation === null) {
            return;
        }
        $stageDirectory = null;
        $promotedDirectory = null;
        $activated = false;
        try {
            $this->updateOperation($operation, 'metadata', 'Laatste KNMI-modelset wordt gecontroleerd.', 1);
            $archive = $this->client->latestArchive();
            $operation->refresh();
            $operation->forceFill([
                'source_filename' => $archive->filename,
                'total_bytes' => $archive->sizeBytes,
            ])->save();

            $active = KnmiForecastSnapshot::query()
                ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
                ->first();
            if ($active !== null && hash_equals((string) $active->source_filename, $archive->filename)) {
                if ((int) $active->source_size_bytes !== $archive->sizeBytes) {
                    throw new KnmiForecastImportException('metadata_changed', 'KNMI reused an archive filename with different metadata.');
                }
                if ($this->snapshotIntegrityMatches($active)) {
                    $this->completeUnchanged($operation, $active);

                    return;
                }
            }

            [$root, $stageDirectory, $releaseStage] = $this->prepareStaging($operation, $archive);
            $tarPath = $stageDirectory.DIRECTORY_SEPARATOR.$archive->filename;
            $lastPersistedBytes = 0;
            $lastPersistedAt = microtime(true);
            $this->updateOperation($operation, 'downloading', 'Volledige KNMI-modelset wordt gedownload.', 2);
            $archiveSha256 = $this->client->download(
                $archive,
                $tarPath,
                function (int $downloaded, int $total) use ($operation, &$lastPersistedBytes, &$lastPersistedAt): void {
                    $now = microtime(true);
                    if ($downloaded < $total
                        && $downloaded - $lastPersistedBytes < 5_242_880
                        && $now - $lastPersistedAt < 2.0) {
                        return;
                    }
                    $percent = $total > 0 ? min(70, 2 + (int) floor(($downloaded / $total) * 68)) : 2;
                    KnmiForecastOperation::query()->whereKey($operation->id)->update([
                        'downloaded_bytes' => max(0, $downloaded),
                        'total_bytes' => max(1, $total),
                        'progress_percent' => $percent,
                        'updated_at' => now(),
                    ]);
                    $lastPersistedBytes = $downloaded;
                    $lastPersistedAt = $now;
                },
            );

            $this->updateOperation($operation, 'extracting', 'Alle 61 KNMI-verwachtingsuren worden veilig uitgepakt.', 72);
            $manifest = $this->extractor->extract($tarPath, $releaseStage, $archive, $archiveSha256);
            $this->updateOperation($operation, 'validating', 'KNMI-parameters en verwachtingstijden worden gecontroleerd.', 90);
            $this->semanticValidator->validate($manifest, $releaseStage);
            $manifestPath = $releaseStage.DIRECTORY_SEPARATOR.'manifest.json';
            $encodedManifest = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
            if (@file_put_contents($manifestPath, $encodedManifest, LOCK_EX) !== strlen($encodedManifest)) {
                throw new KnmiForecastImportException('storage_unavailable', 'KNMI manifest could not be written.');
            }
            if (! @unlink($tarPath)) {
                throw new KnmiForecastImportException('storage_unavailable', 'KNMI source archive could not be removed after extraction.');
            }
            $this->makeReleaseReadOnly($releaseStage);

            $snapshotId = (string) Str::ulid();
            $releaseRelative = 'releases/'.$snapshotId;
            $promotedDirectory = $root.DIRECTORY_SEPARATOR.'releases'.DIRECTORY_SEPARATOR.$snapshotId;
            if (file_exists($promotedDirectory) || ! @rename($releaseStage, $promotedDirectory)) {
                throw new KnmiForecastImportException('storage_unavailable', 'KNMI release could not be promoted atomically.');
            }
            $this->updateOperation($operation, 'activating', 'Gecontroleerde KNMI-modelset wordt geactiveerd.', 96);
            $snapshot = $this->activate($operation, $snapshotId, $releaseRelative, $manifest);
            $activated = true;
            $this->safeDeleteDirectory($stageDirectory, $root.DIRECTORY_SEPARATOR.'staging');
            $stageDirectory = null;

            try {
                $this->pruneOldReleases($snapshot, $root);
            } catch (Throwable $exception) {
                Log::warning('Old KNMI releases could not be pruned.', [
                    'snapshot_id' => $snapshot->id,
                    'exception' => $exception::class,
                ]);
            }
        } catch (Throwable $exception) {
            if ($promotedDirectory !== null && ! $activated) {
                $this->safeDeleteDirectoryBestEffort($promotedDirectory, dirname($promotedDirectory));
            }
            $this->fail($operation, $exception);
        } finally {
            if ($stageDirectory !== null) {
                $root = dirname(dirname($stageDirectory)).DIRECTORY_SEPARATOR.'staging';
                $this->safeDeleteDirectoryBestEffort($stageDirectory, $root);
            }
        }
    }

    public function failFromWorker(string $operationId): void
    {
        $operation = KnmiForecastOperation::query()->find($operationId);
        if ($operation === null || ! $operation->isActive()) {
            return;
        }
        $this->fail($operation, new KnmiForecastImportException(
            'worker_failed',
            'KNMI queue worker stopped before the import completed.',
        ));
        $this->cleanupOperationStageBestEffort($operationId);
    }

    private function markRunning(string $operationId): ?KnmiForecastOperation
    {
        return DB::transaction(function () use ($operationId): ?KnmiForecastOperation {
            $operation = KnmiForecastOperation::query()->lockForUpdate()->find($operationId);
            if ($operation === null || $operation->state !== KnmiForecastOperation::STATE_QUEUED) {
                return null;
            }
            $operation->forceFill([
                'state' => KnmiForecastOperation::STATE_RUNNING,
                'stage' => 'starting',
                'message' => 'KNMI-update is gestart.',
                'started_at' => now(),
                'progress_percent' => 0,
            ])->save();
            $this->audit->record(
                action: 'weather.knmi.refresh_started',
                target: $operation,
                actor: $this->actor($operation),
                metadata: ['state' => KnmiForecastOperation::STATE_RUNNING],
            );

            return $operation;
        });
    }

    /** @return array{string, string, string} */
    private function prepareStaging(KnmiForecastOperation $operation, KnmiOpenDataArchive $archive): array
    {
        $root = $this->secureDirectory($this->configuration->storageRoot());
        $stagingRoot = $this->secureDirectory($root.DIRECTORY_SEPARATOR.'staging');
        $releasesRoot = $this->secureDirectory($root.DIRECTORY_SEPARATOR.'releases');
        $this->sweepStaleStaging($stagingRoot, (string) $operation->id);
        $this->sweepOrphanReleases($releasesRoot);
        $freeBytes = @disk_free_space($root);
        $requiredBytes = ($archive->sizeBytes * 2) + self::DISK_RESERVE_BYTES;
        if (! is_float($freeBytes) || $freeBytes < $requiredBytes) {
            throw new KnmiForecastImportException('insufficient_disk_space', 'Insufficient free disk space for KNMI archive and extracted release.');
        }
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/Di', (string) $operation->id) !== 1) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI operation identifier is invalid.');
        }
        $stage = $stagingRoot.DIRECTORY_SEPARATOR.$operation->id;
        if (file_exists($stage) || is_link($stage)) {
            $this->safeDeleteDirectory($stage, $stagingRoot);
        }
        if (! @mkdir($stage, 0770)) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI staging directories could not be created.');
        }
        if (! @mkdir($stage.DIRECTORY_SEPARATOR.'release', 0770)) {
            $this->safeDeleteDirectoryBestEffort($stage, $stagingRoot);

            throw new KnmiForecastImportException('storage_unavailable', 'KNMI staging directories could not be created.');
        }

        return [$root, $stage, $stage.DIRECTORY_SEPARATOR.'release'];
    }

    /**
     * @param  array{version: int, dataset: string, dataset_version: string, source_filename: string, source_size_bytes: int, source_sha256: string, model_run_at: string, forecast_start_at: string, forecast_end_at: string, members: list<array{filename: string, lead_hours: int, valid_at: string, size_bytes: int, sha256: string}>}  $manifest
     */
    private function activate(
        KnmiForecastOperation $operation,
        string $snapshotId,
        string $releaseRelative,
        array $manifest,
    ): KnmiForecastSnapshot {
        return DB::transaction(function () use ($operation, $snapshotId, $releaseRelative, $manifest): KnmiForecastSnapshot {
            $lockedOperation = KnmiForecastOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($lockedOperation->state !== KnmiForecastOperation::STATE_RUNNING
                || $lockedOperation->active_key !== KnmiForecastOperation::ACTIVE_KEY) {
                throw new KnmiForecastImportException('operation_cancelled', 'KNMI operation is no longer active.');
            }
            KnmiForecastSnapshot::query()
                ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
                ->lockForUpdate()
                ->get();
            KnmiForecastSnapshot::query()
                ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
                ->update(['active_key' => null]);
            $snapshot = new KnmiForecastSnapshot;
            $snapshot->forceFill([
                'id' => $snapshotId,
                'dataset' => $manifest['dataset'],
                'dataset_version' => $manifest['dataset_version'],
                'source_filename' => $manifest['source_filename'],
                'source_size_bytes' => $manifest['source_size_bytes'],
                'source_sha256' => $manifest['source_sha256'],
                'model_run_at' => Carbon::parse($manifest['model_run_at']),
                'forecast_start_at' => Carbon::parse($manifest['forecast_start_at']),
                'forecast_end_at' => Carbon::parse($manifest['forecast_end_at']),
                'member_count' => count($manifest['members']),
                'release_directory' => $releaseRelative,
                'manifest' => $manifest,
                'active_key' => KnmiForecastSnapshot::ACTIVE_KEY,
                'activated_at' => now(),
            ])->save();
            $lockedOperation->forceFill([
                'state' => KnmiForecastOperation::STATE_SUCCEEDED,
                'stage' => 'completed',
                'active_key' => null,
                'message' => 'KNMI-modelset is volledig bijgewerkt.',
                'progress_percent' => 100,
                'downloaded_bytes' => $manifest['source_size_bytes'],
                'total_bytes' => $manifest['source_size_bytes'],
                'snapshot_id' => $snapshot->id,
                'finished_at' => now(),
            ])->save();
            $this->audit->record(
                action: 'weather.knmi.refresh_succeeded',
                target: $snapshot,
                actor: $this->actor($lockedOperation),
                metadata: [
                    'operation_id' => $lockedOperation->id,
                    'source_filename' => $snapshot->source_filename,
                    'source_size_bytes' => $snapshot->source_size_bytes,
                    'member_count' => $snapshot->member_count,
                    'source_sha256' => $snapshot->source_sha256,
                ],
            );

            return $snapshot;
        });
    }

    private function completeUnchanged(KnmiForecastOperation $operation, KnmiForecastSnapshot $snapshot): void
    {
        DB::transaction(function () use ($operation, $snapshot): void {
            $locked = KnmiForecastOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->state !== KnmiForecastOperation::STATE_RUNNING) {
                return;
            }
            $locked->forceFill([
                'state' => KnmiForecastOperation::STATE_SUCCEEDED,
                'stage' => 'completed',
                'active_key' => null,
                'message' => 'De centrale KNMI-modelset is al actueel.',
                'progress_percent' => 100,
                'downloaded_bytes' => 0,
                'unchanged' => true,
                'snapshot_id' => $snapshot->id,
                'finished_at' => now(),
            ])->save();
            $this->audit->record(
                action: 'weather.knmi.refresh_unchanged',
                target: $snapshot,
                actor: $this->actor($locked),
                metadata: [
                    'operation_id' => $locked->id,
                    'source_filename' => $snapshot->source_filename,
                ],
            );
        });
    }

    private function fail(KnmiForecastOperation $operation, Throwable $exception): void
    {
        $code = $exception instanceof KnmiForecastImportException
            ? $exception->publicCode
            : 'internal_error';
        $message = match ($code) {
            'not_configured' => 'KNMI Open Data API is niet geconfigureerd.',
            'metadata_unavailable' => 'KNMI-bestandsinformatie kon niet worden opgehaald.',
            'metadata_invalid', 'metadata_changed' => 'KNMI-bestandsinformatie is ongeldig.',
            'download_url_unavailable', 'download_url_invalid' => 'KNMI-downloadadres is niet veilig beschikbaar.',
            'download_failed' => 'De volledige KNMI-modelset kon niet worden gedownload.',
            'download_size_mismatch', 'download_integrity_failed' => 'De gedownloade KNMI-modelset is niet volledig of beschadigd.',
            'archive_invalid', 'grib_invalid', 'grib_semantic_invalid' => 'De KNMI-modelset heeft de integriteitscontrole niet doorstaan.',
            'insufficient_disk_space' => 'Er is onvoldoende vrije opslagruimte voor de KNMI-update.',
            'storage_unavailable' => 'De centrale KNMI-opslag is niet beschikbaar.',
            'worker_failed' => 'De KNMI-updateworker is gestopt voordat de update was afgerond.',
            default => 'De KNMI-update is door een interne fout afgebroken.',
        };
        DB::transaction(function () use ($operation, $code, $message): void {
            $locked = KnmiForecastOperation::query()->lockForUpdate()->find($operation->id);
            if ($locked === null || ! $locked->isActive()) {
                return;
            }
            $locked->forceFill([
                'state' => KnmiForecastOperation::STATE_FAILED,
                'stage' => 'failed',
                'active_key' => null,
                'message' => $message,
                'error_code' => $code,
                'finished_at' => now(),
            ])->save();
            try {
                $this->audit->record(
                    action: 'weather.knmi.refresh_failed',
                    target: $locked,
                    actor: $this->actor($locked),
                    metadata: ['error_code' => $code],
                );
            } catch (Throwable) {
                // The operation must not remain active when audit persistence is unavailable.
            }
        });
        Log::error('KNMI forecast import failed.', [
            'operation_id' => $operation->id,
            'error_code' => $code,
            'exception' => $exception::class,
        ]);
    }

    private function updateOperation(KnmiForecastOperation $operation, string $stage, string $message, int $progress): void
    {
        KnmiForecastOperation::query()
            ->whereKey($operation->id)
            ->where('state', KnmiForecastOperation::STATE_RUNNING)
            ->update([
                'stage' => $stage,
                'message' => $message,
                'progress_percent' => $progress,
                'updated_at' => now(),
            ]);
    }

    private function secureDirectory(string $path): string
    {
        if (! is_dir($path) && ! @mkdir($path, 0770, true) && ! is_dir($path)) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI storage directory could not be created.');
        }
        if (is_link($path)) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI storage directory may not be a symbolic link.');
        }
        $real = realpath($path);
        if ($real === false || ! is_writable($real)) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI storage directory is not writable.');
        }
        @chmod($real, 0770);

        return $real;
    }

    private function makeReleaseReadOnly(string $release): void
    {
        foreach (scandir($release) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $release.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path) || ! is_file($path) || ! @chmod($path, 0440)) {
                throw new KnmiForecastImportException('storage_unavailable', 'KNMI release permissions could not be secured.');
            }
        }
        if (! @chmod($release, 0770)) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI release directory permissions could not be secured.');
        }
    }

    private function pruneOldReleases(KnmiForecastSnapshot $active, string $root): void
    {
        $keepIds = KnmiForecastSnapshot::query()
            ->latest('activated_at')
            ->latest('id')
            ->limit($this->configuration->retainReleases())
            ->pluck('id')
            ->all();
        $old = KnmiForecastSnapshot::query()
            ->whereNull('active_key')
            ->whereNotIn('id', $keepIds)
            ->get();
        foreach ($old as $snapshot) {
            if ($snapshot->id === $active->id
                || preg_match('/\Areleases\/[0-9A-HJKMNP-TV-Z]{26}\z/Di', (string) $snapshot->release_directory) !== 1) {
                continue;
            }
            $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $snapshot->release_directory);
            $this->safeDeleteDirectory($path, $root.DIRECTORY_SEPARATOR.'releases');
            $snapshot->delete();
        }
    }

    private function snapshotIntegrityMatches(KnmiForecastSnapshot $snapshot): bool
    {
        $members = $this->snapshots->validatedMembers($snapshot);
        if ($members === null) {
            return false;
        }
        foreach ($members as $member) {
            $path = $this->snapshots->absoluteMemberPath($snapshot, $member['filename']);
            if ($path === null) {
                return false;
            }
            clearstatcache(true, $path);
            if (filesize($path) !== $member['size_bytes']
                || ! is_string($sha256 = @hash_file('sha256', $path))
                || ! hash_equals($member['sha256'], $sha256)) {
                return false;
            }
        }

        return true;
    }

    private function sweepStaleStaging(string $stagingRoot, string $currentOperationId): void
    {
        $cutoff = time() - 7_200;
        foreach (scandir($stagingRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === $currentOperationId
                || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/Di', $entry) !== 1) {
                continue;
            }
            $path = $stagingRoot.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path)) {
                continue;
            }
            $related = KnmiForecastOperation::query()->find($entry);
            if ($related?->isActive() === true) {
                continue;
            }
            if ($related === null) {
                $modified = @filemtime($path);
                if (! is_int($modified) || $modified > $cutoff) {
                    continue;
                }
            }
            $this->safeDeleteDirectory($path, $stagingRoot);
        }
    }

    private function sweepOrphanReleases(string $releasesRoot): void
    {
        $referenced = KnmiForecastSnapshot::query()->pluck('release_directory')
            ->map(static fn (string $directory): string => basename(str_replace('\\', '/', $directory)))
            ->flip();
        foreach (scandir($releasesRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $referenced->has($entry)
                || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/Di', $entry) !== 1) {
                continue;
            }
            $path = $releasesRoot.DIRECTORY_SEPARATOR.$entry;
            if (is_link($path)) {
                continue;
            }
            $this->safeDeleteDirectory($path, $releasesRoot);
        }
    }

    private function cleanupOperationStageBestEffort(string $operationId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/Di', $operationId) !== 1) {
            return;
        }
        try {
            $root = $this->configuration->storageRoot();
            $stagingRoot = $root.DIRECTORY_SEPARATOR.'staging';
            if (! is_dir($stagingRoot) || is_link($stagingRoot)) {
                return;
            }
            $realStagingRoot = realpath($stagingRoot);
            if ($realStagingRoot === false) {
                return;
            }
            $this->safeDeleteDirectoryBestEffort(
                $realStagingRoot.DIRECTORY_SEPARATOR.$operationId,
                $realStagingRoot,
            );
        } catch (Throwable) {
            // The next import repeats the same bounded cleanup sweep.
        }
    }

    private function safeDeleteDirectoryBestEffort(string $path, string $parent): void
    {
        try {
            $this->safeDeleteDirectory($path, $parent);
        } catch (Throwable) {
            // The active release is never targeted. A later update can remove its own stale stage.
        }
    }

    private function safeDeleteDirectory(string $path, string $parent): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        $parentReal = realpath($parent);
        $pathParentReal = realpath(dirname($path));
        if ($parentReal === false || $pathParentReal === false
            || ! hash_equals($this->normalizedPath($parentReal), $this->normalizedPath($pathParentReal))
            || is_link($path)) {
            throw new KnmiForecastImportException('storage_unavailable', 'Unsafe KNMI cleanup target was rejected.');
        }
        if (! is_dir($path)) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI cleanup target is not a directory.');
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_link($child)) {
                if (! @unlink($child)) {
                    throw new KnmiForecastImportException('storage_unavailable', 'KNMI cleanup could not remove a symbolic link.');
                }
            } elseif (is_file($child)) {
                if (! @unlink($child)) {
                    throw new KnmiForecastImportException('storage_unavailable', 'KNMI cleanup could not remove a file.');
                }
            } elseif (is_dir($child)) {
                $this->safeDeleteDirectory($child, $path);
            } else {
                throw new KnmiForecastImportException('storage_unavailable', 'KNMI cleanup encountered an unsafe entry.');
            }
        }
        if (! @rmdir($path)) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI cleanup could not remove a directory.');
        }
    }

    private function normalizedPath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }

    private function actor(KnmiForecastOperation $operation): ?User
    {
        return $operation->requested_by === null
            ? null
            : User::query()->find($operation->requested_by);
    }
}
