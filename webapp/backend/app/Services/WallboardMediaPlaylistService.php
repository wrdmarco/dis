<?php

namespace App\Services;

use App\Models\User;
use App\Models\WallboardMediaAsset;
use App\Models\WallboardMediaPlaylist;
use App\Models\WallboardMediaPlaylistItem;
use App\Models\WallboardMediaPlaylistUsage;
use App\Models\WallboardPlaylist;
use App\Repositories\WallboardMediaAssetRepository;
use App\Repositories\WallboardMediaPlaylistRepository;
use App\Repositories\WallboardPlaylistRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WallboardMediaPlaylistService
{
    public const MAX_ITEMS = 100;

    public function __construct(
        private readonly WallboardMediaPlaylistRepository $repository,
        private readonly WallboardMediaAssetRepository $assets,
        private readonly WallboardPlaylistRepository $wallboardPlaylists,
        private readonly WallboardPlaylistSynchronizer $wallboardSynchronizer,
        private readonly WallboardMediaCoordinationService $coordination,
        private readonly WallboardMediaUsageSynchronizer $usageSynchronizer,
        private readonly AuditService $auditService,
    ) {}

    /** @return Collection<int, WallboardMediaPlaylist> */
    public function all(): Collection
    {
        return $this->repository->allForManagement();
    }

    public function show(WallboardMediaPlaylist $playlist): WallboardMediaPlaylist
    {
        return $this->repository->findForManagement((string) $playlist->getKey());
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, Request $request): WallboardMediaPlaylist
    {
        return DB::transaction(function () use ($data, $actor, $request): WallboardMediaPlaylist {
            $assetIds = $this->assetIds($data['asset_ids'] ?? []);
            $this->readyAssets($assetIds);
            $created = $this->repository->create([
                'name' => $this->name((string) $data['name']),
                'version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            if (! $created instanceof WallboardMediaPlaylist) {
                throw new \LogicException('Wallboard media playlist repository returned an unexpected model.');
            }
            $this->replaceItems($created, $assetIds);
            $this->auditService->record('wallboard_media.playlists.created', $created, $actor, [
                'name' => $created->name,
                'item_count' => count($assetIds),
                'version' => 1,
            ], null, $request);

            return $this->repository->findForManagement((string) $created->id);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(
        WallboardMediaPlaylist $playlist,
        array $data,
        User $actor,
        Request $request,
    ): WallboardMediaPlaylist {
        return DB::transaction(function () use (
            $playlist,
            $data,
            $actor,
            $request,
        ): WallboardMediaPlaylist {
            $this->coordination->lock();
            $referencingIds = $this->repository->wallboardPlaylistIdsUsing((string) $playlist->getKey());
            $lockedWallboardPlaylists = collect();
            foreach ($referencingIds as $wallboardPlaylistId) {
                $lockedWallboardPlaylists->push($this->wallboardPlaylists->lockPlaylist($wallboardPlaylistId));
            }
            $locked = $this->repository->lockPlaylist((string) $playlist->getKey());
            if ((int) $data['expected_version'] !== (int) $locked->version) {
                throw new ConflictHttpException('De fotoplaylist is gewijzigd.');
            }

            $existingAssetIds = $locked->items()
                ->orderBy('position')
                ->pluck('media_asset_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();
            $assetIds = array_key_exists('asset_ids', $data)
                ? $this->assetIds($data['asset_ids'])
                : $existingAssetIds;
            $this->readyAssets($assetIds);
            $this->assertReferencedDurations($locked, count($assetIds), $lockedWallboardPlaylists);

            $itemsChanged = $assetIds !== $existingAssetIds;
            $locked->forceFill([
                'name' => array_key_exists('name', $data) ? $this->name((string) $data['name']) : $locked->name,
                'version' => (int) $locked->version + 1,
                'updated_by' => $actor->id,
            ])->save();
            if ($itemsChanged) {
                $this->replaceItems($locked, $assetIds);
                $this->touchReferencedWallboards(
                    $lockedWallboardPlaylists,
                    (string) $locked->id,
                    count($assetIds),
                    $actor,
                );
            }
            $this->auditService->record('wallboard_media.playlists.updated', $locked, $actor, [
                'changed_fields' => array_values(array_diff(array_keys($data), ['expected_version'])),
                'item_count' => count($assetIds),
                'linked_wallboard_playlists_count' => $lockedWallboardPlaylists->count(),
                'version' => (int) $locked->version,
            ], null, $request);

            return $this->repository->findForManagement((string) $locked->id);
        }, 3);
    }

    public function delete(
        WallboardMediaPlaylist $playlist,
        int $expectedVersion,
        User $actor,
        Request $request,
    ): void {
        DB::transaction(function () use ($playlist, $expectedVersion, $actor, $request): void {
            $this->coordination->lock();
            $locked = $this->repository->lockPlaylist((string) $playlist->getKey());
            if ($expectedVersion !== (int) $locked->version) {
                throw new ConflictHttpException('De fotoplaylist is gewijzigd.');
            }
            if ($this->repository->isUsed((string) $locked->id)) {
                throw new ConflictHttpException('Deze fotoplaylist wordt nog door een wallboardplaylist gebruikt.');
            }

            $this->auditService->record('wallboard_media.playlists.deleted', $locked, $actor, [
                'name' => $locked->name,
                'item_count' => $locked->items()->count(),
                'version' => (int) $locked->version,
            ], null, $request);
            $locked->delete();
        }, 3);
    }

    /**
     * @return list<string>
     */
    private function assetIds(mixed $value): array
    {
        if (! is_array($value) || $value === [] || count($value) > self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'asset_ids' => ['Een fotoplaylist bevat 1 tot en met '.self::MAX_ITEMS.' afbeeldingen.'],
            ]);
        }
        $ids = [];
        foreach ($value as $index => $id) {
            $candidate = trim((string) $id);
            if (! Str::isUlid($candidate) || isset($ids[$candidate])) {
                throw ValidationException::withMessages([
                    "asset_ids.{$index}" => ['Selecteer iedere geldige afbeelding maximaal één keer.'],
                ]);
            }
            $ids[$candidate] = true;
        }

        return array_keys($ids);
    }

    /** @param list<string> $ids
     * @return Collection<int, WallboardMediaAsset>
     */
    private function readyAssets(array $ids): Collection
    {
        $assets = $this->assets->lockReadyAssets($ids);
        if ($assets->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'asset_ids' => ['Een of meer afbeeldingen bestaan niet of zijn niet beschikbaar.'],
            ]);
        }
        if ($assets->contains(fn (WallboardMediaAsset $asset): bool => ($asset->kind ?: WallboardMediaAsset::KIND_IMAGE)
            !== WallboardMediaAsset::KIND_IMAGE)) {
            throw ValidationException::withMessages([
                'asset_ids' => ['Een fotoplaylist mag uitsluitend afbeeldingen bevatten.'],
            ]);
        }

        return $assets;
    }

    /** @param list<string> $assetIds */
    private function replaceItems(WallboardMediaPlaylist $playlist, array $assetIds): void
    {
        $playlist->items()->delete();
        foreach ($assetIds as $position => $assetId) {
            WallboardMediaPlaylistItem::query()->create([
                'media_playlist_id' => (string) $playlist->id,
                'media_asset_id' => $assetId,
                'position' => $position,
            ]);
        }
    }

    /** @param \Illuminate\Support\Collection<int, WallboardPlaylist> $wallboardPlaylists */
    private function assertReferencedDurations(
        WallboardMediaPlaylist $mediaPlaylist,
        int $itemCount,
        \Illuminate\Support\Collection $wallboardPlaylists,
    ): void {
        $byId = $wallboardPlaylists->keyBy(fn (WallboardPlaylist $playlist): string => (string) $playlist->id);
        $usages = WallboardMediaPlaylistUsage::query()
            ->where('media_playlist_id', (string) $mediaPlaylist->id)
            ->orderBy('wallboard_playlist_id')
            ->orderBy('page_id')
            ->get();
        foreach ($usages as $usage) {
            $wallboardPlaylist = $byId->get((string) $usage->wallboard_playlist_id);
            if (! $wallboardPlaylist instanceof WallboardPlaylist) {
                throw new ConflictHttpException('Een gekoppelde wallboardplaylist is gelijktijdig gewijzigd.');
            }
            $reference = collect((array) ($wallboardPlaylist->configuration['pages'] ?? []))
                ->first(fn (mixed $page): bool => is_array($page)
                    && (string) ($page['id'] ?? '') === (string) $usage->page_id
                    && ($page['type'] ?? null) === WallboardMediaUsageSynchronizer::PAGE_TYPE);
            if (! is_array($reference)) {
                throw new ConflictHttpException('De fotoplaylistkoppeling is niet meer actueel.');
            }
            $duration = (int) (($reference['options'] ?? [])['item_duration_seconds'] ?? 0);
            if ($duration < WallboardMediaUsageSynchronizer::MIN_ITEM_DURATION_SECONDS
                || $duration > WallboardMediaUsageSynchronizer::MAX_ITEM_DURATION_SECONDS
                || $itemCount > intdiv(WallboardMediaUsageSynchronizer::MAX_PAGE_DURATION_SECONDS, $duration)) {
                throw ValidationException::withMessages([
                    'asset_ids' => ['Deze wijziging maakt een gekoppelde fotocarrousel langer dan 3600 seconden.'],
                ]);
            }
        }
    }

    /** @param \Illuminate\Support\Collection<int, WallboardPlaylist> $wallboardPlaylists */
    private function touchReferencedWallboards(
        \Illuminate\Support\Collection $wallboardPlaylists,
        string $mediaPlaylistId,
        int $readyItemCount,
        User $actor,
    ): void {
        foreach ($wallboardPlaylists as $playlist) {
            $configuration = $this->usageSynchronizer->deriveForMediaPlaylist(
                (array) $playlist->configuration,
                $mediaPlaylistId,
                $readyItemCount,
            );
            $wallboards = $this->wallboardPlaylists->lockLinkedWallboards((string) $playlist->id);
            $this->wallboardSynchronizer->updatePlaylistAndLinkedWallboards(
                $playlist,
                $wallboards,
                $configuration,
                $actor,
            );
        }
    }

    private function name(string $name): string
    {
        $clean = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        if ($clean === '' || mb_strlen($clean) > 120 || $clean !== strip_tags($clean)
            || preg_match('/[\x00-\x1F\x7F]/u', $clean) === 1) {
            throw ValidationException::withMessages(['name' => ['Geef een geldige fotoplaylistnaam op.']]);
        }

        return $clean;
    }
}
