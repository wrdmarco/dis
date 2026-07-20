<?php

namespace App\Services;

use App\Jobs\TranscodeWallboardMediaVideo;
use App\Models\User;
use App\Models\WallboardMediaAsset;
use App\Repositories\WallboardMediaAssetRepository;
use App\Repositories\WallboardMediaFolderRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class WallboardMediaAssetService
{
    public function __construct(
        private readonly WallboardMediaAssetRepository $repository,
        private readonly WallboardMediaFolderRepository $folders,
        private readonly WallboardMediaImageProcessor $processor,
        private readonly WallboardMediaVideoProcessor $videoProcessor,
        private readonly WallboardMediaQuotaService $quota,
        private readonly WallboardMediaCoordinationService $coordination,
        private readonly AuditService $auditService,
    ) {}

    public function paginate(
        ?string $folderId,
        ?string $search,
        int $perPage,
        ?string $kind = null,
        ?string $status = null,
    ): LengthAwarePaginator {
        return $this->repository->paginateForManagement($folderId, $search, $perPage, $kind, $status);
    }

    public function show(WallboardMediaAsset $asset): WallboardMediaAsset
    {
        return $this->repository->findForManagement((string) $asset->getKey());
    }

    /** @param array<string, mixed> $data */
    public function upload(array $data, User $actor, Request $request): WallboardMediaAsset
    {
        $field = array_key_exists('file', $data) ? 'file' : 'image';
        $upload = $data[$field] ?? null;
        if (! $upload instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => ['Selecteer een afbeelding of MP4-video om te uploaden.']]);
        }
        $detectedMime = $this->detectedMime($upload);
        $processed = $detectedMime === 'video/mp4'
            ? $this->videoProcessor->process($upload, $field)
            : $this->processor->process($upload, $field);
        $assetId = (string) Str::ulid();
        $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');
        $storagePath = $root.'/objects/'.$assetId.($processed->kind === WallboardMediaAsset::KIND_VIDEO ? '.mp4' : '.webp');
        $thumbnailStoragePath = $processed->thumbnailTemporaryPath === null
            ? null
            : $root.'/objects/'.$assetId.'.thumbnail.webp';
        $diskName = (string) config('wallboard_media.disk', 'local');
        $stored = false;
        $thumbnailStored = false;

        try {
            return $this->quota->reserve($processed->byteSize + ($processed->thumbnailByteSize ?? 0), function () use (
                $data,
                $actor,
                $request,
                $upload,
                $processed,
                $assetId,
                $storagePath,
                $thumbnailStoragePath,
                $diskName,
                &$stored,
                &$thumbnailStored,
            ): WallboardMediaAsset {
                return DB::transaction(function () use (
                    $data,
                    $actor,
                    $request,
                    $upload,
                    $processed,
                    $assetId,
                    $storagePath,
                    $thumbnailStoragePath,
                    $diskName,
                    &$stored,
                    &$thumbnailStored,
                ): WallboardMediaAsset {
                    $folderId = $this->nullableId($data['folder_id'] ?? null);
                    if ($folderId !== null) {
                        $this->folders->lockFolder($folderId);
                    }
                    $displayName = $this->displayName(
                        $data['display_name'] ?? null,
                        $upload->getClientOriginalName(),
                    );
                    $originalName = $this->originalName($upload->getClientOriginalName());
                    $created = $this->repository->create([
                        'id' => $assetId,
                        'folder_id' => $folderId,
                        'display_name' => $displayName,
                        'original_name' => $originalName,
                        'kind' => $processed->kind,
                        'storage_path' => $storagePath,
                        'thumbnail_storage_path' => $thumbnailStoragePath,
                        'thumbnail_sha256' => $processed->thumbnailSha256,
                        'thumbnail_mime_type' => $thumbnailStoragePath === null ? null : 'image/webp',
                        'thumbnail_byte_size' => $processed->thumbnailByteSize,
                        'sha256' => $processed->sha256,
                        'mime_type' => $processed->mimeType,
                        'byte_size' => $processed->byteSize,
                        'width' => $processed->width,
                        'height' => $processed->height,
                        'duration_seconds' => $processed->durationSeconds,
                        'status' => WallboardMediaAsset::STATUS_PROCESSING,
                        'version' => 1,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                    if (! $created instanceof WallboardMediaAsset) {
                        throw new \LogicException('Wallboard media asset repository returned an unexpected model.');
                    }

                    $disk = Storage::disk($diskName);
                    $destination = $disk->path($storagePath);
                    $directory = dirname($destination);
                    if ((! is_dir($directory) && ! @mkdir($directory, 0770, true))
                        || ! @rename($processed->temporaryPath, $destination)) {
                        throw new HttpException(503, 'De afbeelding kon niet veilig worden opgeslagen.');
                    }
                    $stored = true;
                    @chmod($directory, 0770);
                    @chmod($destination, 0640);
                    if ($thumbnailStoragePath !== null && $processed->thumbnailTemporaryPath !== null) {
                        $thumbnailDestination = $disk->path($thumbnailStoragePath);
                        if (! @rename($processed->thumbnailTemporaryPath, $thumbnailDestination)) {
                            throw new HttpException(503, 'De miniatuur kon niet veilig worden opgeslagen.');
                        }
                        $thumbnailStored = true;
                        @chmod($thumbnailDestination, 0640);
                    }

                    $created->forceFill([
                        'status' => $processed->requiresVideoTranscode
                            ? WallboardMediaAsset::STATUS_PROCESSING
                            : WallboardMediaAsset::STATUS_READY,
                    ])->save();
                    if ($processed->requiresVideoTranscode) {
                        DB::afterCommit(fn () => TranscodeWallboardMediaVideo::dispatch((string) $created->id));
                    }
                    $this->auditService->record('wallboard_media.assets.uploaded', $created, $actor, [
                        'folder_id' => $folderId,
                        'kind' => $processed->kind,
                        'mime_type' => $processed->mimeType,
                        'byte_size' => $processed->byteSize,
                        'width' => $processed->width,
                        'height' => $processed->height,
                        'duration_seconds' => $processed->durationSeconds,
                        'video_transcode_required' => $processed->requiresVideoTranscode,
                        'status' => $created->status,
                        'sha256_prefix' => substr($processed->sha256, 0, 12),
                        'version' => 1,
                    ], null, $request);

                    return $this->repository->findForManagement((string) $created->id);
                }, 3);
            });
        } catch (Throwable $exception) {
            if ($stored) {
                try {
                    Storage::disk($diskName)->delete($storagePath);
                } catch (Throwable) {
                    // The database transaction remains rolled back and the opaque
                    // orphan path is never publicly addressable.
                }
            }
            if ($thumbnailStored && $thumbnailStoragePath !== null) {
                try {
                    Storage::disk($diskName)->delete($thumbnailStoragePath);
                } catch (Throwable) {
                    // The opaque orphan is removed by scheduled cleanup.
                }
            }

            throw $exception;
        } finally {
            if (is_file($processed->temporaryPath)) {
                @unlink($processed->temporaryPath);
            }
            if ($processed->thumbnailTemporaryPath !== null && is_file($processed->thumbnailTemporaryPath)) {
                @unlink($processed->thumbnailTemporaryPath);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public function update(
        WallboardMediaAsset $asset,
        array $data,
        User $actor,
        Request $request,
    ): WallboardMediaAsset {
        return DB::transaction(function () use ($asset, $data, $actor, $request): WallboardMediaAsset {
            $folderId = array_key_exists('folder_id', $data) ? $this->nullableId($data['folder_id']) : null;
            if (array_key_exists('folder_id', $data) && $folderId !== null) {
                $this->folders->lockFolder($folderId);
            }
            $locked = $this->repository->lockAsset((string) $asset->getKey());
            if ((int) $data['expected_version'] !== (int) $locked->version) {
                throw new ConflictHttpException('Het mediabestand is gewijzigd.');
            }

            $changes = [
                'version' => (int) $locked->version + 1,
                'updated_by' => $actor->id,
            ];
            if (array_key_exists('folder_id', $data)) {
                $changes['folder_id'] = $folderId;
            }
            if (array_key_exists('display_name', $data)) {
                $changes['display_name'] = $this->displayName($data['display_name'], (string) $locked->original_name);
            }
            $locked->forceFill($changes)->save();
            $this->auditService->record('wallboard_media.assets.updated', $locked, $actor, [
                'changed_fields' => array_values(array_diff(array_keys($data), ['expected_version'])),
                'folder_id' => $locked->folder_id,
                'version' => (int) $locked->version,
            ], null, $request);

            return $this->repository->findForManagement((string) $locked->id);
        }, 3);
    }

    public function delete(
        WallboardMediaAsset $asset,
        int $expectedVersion,
        User $actor,
        Request $request,
    ): void {
        $deleted = DB::transaction(function () use ($asset, $expectedVersion, $actor, $request): WallboardMediaAsset {
            $this->coordination->lock();
            $locked = $this->repository->lockAsset((string) $asset->getKey());
            if ($expectedVersion !== (int) $locked->version) {
                throw new ConflictHttpException('Het mediabestand is gewijzigd.');
            }
            if ($this->repository->usedByPlaylist((string) $locked->id)) {
                throw new ConflictHttpException('Dit mediabestand wordt nog door een wallboardplaylist gebruikt.');
            }

            $this->auditService->record('wallboard_media.assets.deleted', $locked, $actor, [
                'display_name' => $locked->display_name,
                'byte_size' => (int) $locked->byte_size,
                'version' => (int) $locked->version,
            ], null, $request);
            $locked->delete();

            return $locked;
        }, 3);

        try {
            $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');
            $canonicalThumbnailPath = $deleted->kind === WallboardMediaAsset::KIND_IMAGE
                ? $root.'/objects/'.(string) $deleted->id.'.thumbnail.webp'
                : null;
            Storage::disk((string) config('wallboard_media.disk', 'local'))->delete(array_values(array_unique(array_filter([
                (string) $deleted->storage_path,
                $deleted->thumbnail_storage_path === null ? null : (string) $deleted->thumbnail_storage_path,
                $canonicalThumbnailPath,
            ]))));
        } catch (Throwable) {
            $this->auditService->record('wallboard_media.assets.cleanup_deferred', $deleted, $actor, [
                'byte_size' => (int) $deleted->byte_size,
            ], null, $request);
        }
    }

    private function displayName(mixed $preferred, string $originalName): string
    {
        $candidate = trim((string) ($preferred ?? ''));
        if ($candidate === '') {
            $candidate = pathinfo($originalName, PATHINFO_FILENAME);
        }
        $candidate = preg_replace('/\s+/u', ' ', trim($candidate)) ?? '';
        if ($candidate === '' || mb_strlen($candidate) > 180 || $candidate !== strip_tags($candidate)
            || preg_match('/[\x00-\x1F\x7F]/u', $candidate) === 1) {
            throw ValidationException::withMessages(['display_name' => ['Geef een geldige medianaam op.']]);
        }

        return $candidate;
    }

    private function originalName(string $name): string
    {
        $clean = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $clean) ?? '';
        $clean = mb_substr(trim($clean), 0, 255);

        return $clean === '' ? 'mediabestand' : $clean;
    }

    private function detectedMime(UploadedFile $upload): string
    {
        $path = $upload->getRealPath();
        if (! is_string($path)) {
            return '';
        }
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        } catch (Throwable) {
            return '';
        }

        return is_string($mime) ? strtolower(trim($mime)) : '';
    }

    private function nullableId(mixed $id): ?string
    {
        $value = trim((string) ($id ?? ''));

        return $value === '' ? null : $value;
    }
}
