<?php

namespace App\Services;

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
        private readonly WallboardMediaQuotaService $quota,
        private readonly AuditService $auditService,
    ) {}

    public function paginate(?string $folderId, ?string $search, int $perPage): LengthAwarePaginator
    {
        return $this->repository->paginateForManagement($folderId, $search, $perPage);
    }

    public function show(WallboardMediaAsset $asset): WallboardMediaAsset
    {
        return $this->repository->findForManagement((string) $asset->getKey());
    }

    /** @param array<string, mixed> $data */
    public function upload(array $data, User $actor, Request $request): WallboardMediaAsset
    {
        $upload = $data['image'] ?? null;
        if (! $upload instanceof UploadedFile) {
            throw ValidationException::withMessages(['image' => ['Selecteer een afbeelding om te uploaden.']]);
        }
        $processed = $this->processor->process($upload);
        $assetId = (string) Str::ulid();
        $root = trim((string) config('wallboard_media.root', 'wallboard-media'), '/');
        $storagePath = $root.'/objects/'.$assetId.'.webp';
        $diskName = (string) config('wallboard_media.disk', 'local');
        $stored = false;

        try {
            return $this->quota->reserve($processed->byteSize, function () use (
                $data,
                $actor,
                $request,
                $upload,
                $processed,
                $assetId,
                $storagePath,
                $diskName,
                &$stored,
            ): WallboardMediaAsset {
                return DB::transaction(function () use (
                    $data,
                    $actor,
                    $request,
                    $upload,
                    $processed,
                    $assetId,
                    $storagePath,
                    $diskName,
                    &$stored,
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
                        'storage_path' => $storagePath,
                        'sha256' => $processed->sha256,
                        'mime_type' => 'image/webp',
                        'byte_size' => $processed->byteSize,
                        'width' => $processed->width,
                        'height' => $processed->height,
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

                    $created->forceFill(['status' => WallboardMediaAsset::STATUS_READY])->save();
                    $this->auditService->record('wallboard_media.assets.uploaded', $created, $actor, [
                        'folder_id' => $folderId,
                        'mime_type' => 'image/webp',
                        'byte_size' => $processed->byteSize,
                        'width' => $processed->width,
                        'height' => $processed->height,
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

            throw $exception;
        } finally {
            if (is_file($processed->temporaryPath)) {
                @unlink($processed->temporaryPath);
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
                throw new ConflictHttpException('De media-afbeelding is gewijzigd.');
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
            $locked = $this->repository->lockAsset((string) $asset->getKey());
            if ($expectedVersion !== (int) $locked->version) {
                throw new ConflictHttpException('De media-afbeelding is gewijzigd.');
            }
            if ($this->repository->usedByPlaylist((string) $locked->id)) {
                throw new ConflictHttpException('Deze afbeelding wordt nog door een fotoplaylist gebruikt.');
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
            Storage::disk((string) config('wallboard_media.disk', 'local'))->delete((string) $deleted->storage_path);
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
            throw ValidationException::withMessages(['display_name' => ['Geef een geldige afbeeldingsnaam op.']]);
        }

        return $candidate;
    }

    private function originalName(string $name): string
    {
        $clean = basename(str_replace('\\', '/', $name));
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $clean) ?? '';
        $clean = mb_substr(trim($clean), 0, 255);

        return $clean === '' ? 'afbeelding' : $clean;
    }

    private function nullableId(mixed $id): ?string
    {
        $value = trim((string) ($id ?? ''));

        return $value === '' ? null : $value;
    }
}
