<?php

namespace App\Services;

use App\Models\User;
use App\Models\WallboardMediaFolder;
use App\Repositories\WallboardMediaFolderRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WallboardMediaFolderService
{
    public const MAX_DEPTH = 8;

    public function __construct(
        private readonly WallboardMediaFolderRepository $repository,
        private readonly AuditService $auditService,
    ) {}

    /** @return Collection<int, WallboardMediaFolder> */
    public function all(): Collection
    {
        return $this->repository->allForManagement();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, Request $request): WallboardMediaFolder
    {
        try {
            return DB::transaction(function () use ($data, $actor, $request): WallboardMediaFolder {
                $folders = $this->repository->lockAll();
                $parentId = $this->nullableId($data['parent_id'] ?? null);
                $name = $this->cleanName((string) $data['name']);
                $normalizedName = $this->normalizedName($name);
                $this->assertParentAndDepth($folders, null, $parentId);
                $this->assertUniqueSiblingName($folders, null, $parentId, $normalizedName);

                $created = $this->repository->create([
                    'parent_id' => $parentId,
                    'parent_scope' => $parentId ?? WallboardMediaFolder::ROOT_SCOPE,
                    'name' => $name,
                    'normalized_name' => $normalizedName,
                    'version' => 1,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                if (! $created instanceof WallboardMediaFolder) {
                    throw new \LogicException('Wallboard media folder repository returned an unexpected model.');
                }
                $this->auditService->record('wallboard_media.folders.created', $created, $actor, [
                    'parent_id' => $parentId,
                    'name' => $name,
                    'version' => 1,
                ], null, $request);

                return $created->loadCount(['children', 'assets']);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => ['In deze map bestaat al een map met dezelfde naam.'],
            ]);
        }
    }

    /** @param array<string, mixed> $data */
    public function update(
        WallboardMediaFolder $folder,
        array $data,
        User $actor,
        Request $request,
    ): WallboardMediaFolder {
        try {
            return DB::transaction(function () use ($folder, $data, $actor, $request): WallboardMediaFolder {
                $folders = $this->repository->lockAll();
                $locked = $folders->firstWhere('id', (string) $folder->getKey());
                abort_unless($locked instanceof WallboardMediaFolder, 404);
                if ((int) $data['expected_version'] !== (int) $locked->version) {
                    throw new ConflictHttpException('De mediamap is gewijzigd.');
                }

                $parentId = array_key_exists('parent_id', $data)
                    ? $this->nullableId($data['parent_id'])
                    : $this->nullableId($locked->parent_id);
                $name = array_key_exists('name', $data)
                    ? $this->cleanName((string) $data['name'])
                    : (string) $locked->name;
                $normalizedName = $this->normalizedName($name);
                $this->assertParentAndDepth($folders, (string) $locked->id, $parentId);
                $this->assertUniqueSiblingName(
                    $folders,
                    (string) $locked->id,
                    $parentId,
                    $normalizedName,
                );

                $locked->forceFill([
                    'parent_id' => $parentId,
                    'parent_scope' => $parentId ?? WallboardMediaFolder::ROOT_SCOPE,
                    'name' => $name,
                    'normalized_name' => $normalizedName,
                    'version' => (int) $locked->version + 1,
                    'updated_by' => $actor->id,
                ])->save();
                $this->auditService->record('wallboard_media.folders.updated', $locked, $actor, [
                    'changed_fields' => array_values(array_diff(array_keys($data), ['expected_version'])),
                    'parent_id' => $parentId,
                    'version' => (int) $locked->version,
                ], null, $request);

                return $locked->loadCount(['children', 'assets']);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'name' => ['In deze map bestaat al een map met dezelfde naam.'],
            ]);
        }
    }

    public function delete(
        WallboardMediaFolder $folder,
        int $expectedVersion,
        User $actor,
        Request $request,
    ): void {
        DB::transaction(function () use ($folder, $expectedVersion, $actor, $request): void {
            $locked = $this->repository->lockFolder((string) $folder->getKey());
            if ($expectedVersion !== (int) $locked->version) {
                throw new ConflictHttpException('De mediamap is gewijzigd.');
            }
            if ($locked->children()->exists() || $locked->assets()->exists()) {
                throw new ConflictHttpException('Alleen een lege mediamap kan worden verwijderd.');
            }

            $this->auditService->record('wallboard_media.folders.deleted', $locked, $actor, [
                'name' => $locked->name,
                'version' => (int) $locked->version,
            ], null, $request);
            $locked->delete();
        }, 3);
    }

    /** @param Collection<int, WallboardMediaFolder> $folders */
    private function assertParentAndDepth(Collection $folders, ?string $folderId, ?string $parentId): void
    {
        if ($parentId === null) {
            $parentDepth = 0;
        } else {
            if ($parentId === $folderId) {
                throw ValidationException::withMessages(['parent_id' => ['Een map kan niet zichzelf bevatten.']]);
            }
            $byId = $folders->keyBy(fn (WallboardMediaFolder $folder): string => (string) $folder->id);
            $parent = $byId->get($parentId);
            if (! $parent instanceof WallboardMediaFolder) {
                throw ValidationException::withMessages(['parent_id' => ['De gekozen bovenliggende map bestaat niet.']]);
            }
            $parentDepth = 1;
            $cursor = $parent;
            $visited = [];
            while ($cursor->parent_id !== null) {
                $cursorId = (string) $cursor->id;
                if (isset($visited[$cursorId]) || (string) $cursor->parent_id === $folderId) {
                    throw ValidationException::withMessages(['parent_id' => ['Een map kan niet naar een eigen submap worden verplaatst.']]);
                }
                $visited[$cursorId] = true;
                $next = $byId->get((string) $cursor->parent_id);
                if (! $next instanceof WallboardMediaFolder) {
                    throw new \LogicException('Wallboard media folder tree is inconsistent.');
                }
                $cursor = $next;
                $parentDepth++;
            }
        }

        $subtreeHeight = $folderId === null ? 0 : $this->subtreeHeight($folders, $folderId);
        if ($parentDepth + 1 + $subtreeHeight > self::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => ['Mediamappen mogen maximaal '.self::MAX_DEPTH.' niveaus diep zijn.'],
            ]);
        }
    }

    /** @param Collection<int, WallboardMediaFolder> $folders */
    private function subtreeHeight(Collection $folders, string $folderId): int
    {
        $childrenByParent = $folders->groupBy(fn (WallboardMediaFolder $folder): string => (string) ($folder->parent_id ?? ''));
        $height = static function (string $id, array $visited = []) use (&$height, $childrenByParent): int {
            if (isset($visited[$id])) {
                throw new \LogicException('Wallboard media folder tree contains a cycle.');
            }
            $visited[$id] = true;
            $children = $childrenByParent->get($id, collect());
            if ($children->isEmpty()) {
                return 0;
            }

            return 1 + (int) $children
                ->map(fn (WallboardMediaFolder $child): int => $height((string) $child->id, $visited))
                ->max();
        };

        return $height($folderId);
    }

    /** @param Collection<int, WallboardMediaFolder> $folders */
    private function assertUniqueSiblingName(
        Collection $folders,
        ?string $folderId,
        ?string $parentId,
        string $normalizedName,
    ): void {
        $duplicate = $folders->contains(function (WallboardMediaFolder $candidate) use (
            $folderId,
            $parentId,
            $normalizedName,
        ): bool {
            return (string) $candidate->id !== (string) $folderId
                && $this->nullableId($candidate->parent_id) === $parentId
                && hash_equals((string) $candidate->normalized_name, $normalizedName);
        });
        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['In deze map bestaat al een map met dezelfde naam.'],
            ]);
        }
    }

    private function cleanName(string $name): string
    {
        $clean = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        if ($clean === '' || mb_strlen($clean) > 120 || $clean !== strip_tags($clean)
            || preg_match('/[\x00-\x1F\x7F]/u', $clean) === 1) {
            throw ValidationException::withMessages(['name' => ['Geef een geldige mapnaam op.']]);
        }

        return $clean;
    }

    private function normalizedName(string $name): string
    {
        return mb_strtolower($name, 'UTF-8');
    }

    private function nullableId(mixed $id): ?string
    {
        $value = trim((string) ($id ?? ''));

        return $value === '' ? null : $value;
    }
}
