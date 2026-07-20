<?php

namespace App\Services;

use App\Models\Wallboard;
use App\Models\WallboardMediaAsset;
use App\Models\WallboardMediaPlaylist;
use App\Models\WallboardMediaPlaylistUsage;
use App\Repositories\WallboardMediaAssetRepository;
use App\Repositories\WallboardMediaPlaylistRepository;
use App\Support\WallboardConfiguration;
use Illuminate\Validation\ValidationException;

final class WallboardMediaStateService
{
    public function __construct(
        private readonly WallboardMediaUsageSynchronizer $usageSynchronizer,
        private readonly WallboardMediaPlaylistRepository $playlists,
        private readonly WallboardMediaAssetRepository $assets,
    ) {}

    /** @return array<string, array<string, mixed>> */
    /** @param array<string, mixed> $resolvedConfiguration */
    public function pages(Wallboard $wallboard, array $resolvedConfiguration): array
    {
        return $this->pagesForPlaylist(
            is_string($wallboard->playlist_id) ? $wallboard->playlist_id : null,
            $resolvedConfiguration,
        );
    }

    /** @return array<string, array<string, mixed>> */
    /** @param array<string, mixed> $resolvedConfiguration */
    public function pagesForPlaylist(?string $wallboardPlaylistId, array $resolvedConfiguration): array
    {
        if (! is_string($wallboardPlaylistId) || $wallboardPlaylistId === '') {
            return [];
        }

        $usages = WallboardMediaPlaylistUsage::query()
            ->where('wallboard_playlist_id', $wallboardPlaylistId)
            ->with(['mediaPlaylist.items.asset'])
            ->orderBy('page_id')
            ->get();
        $configurationPages = collect((array) ($resolvedConfiguration['pages'] ?? []))
            ->filter(fn (mixed $page): bool => is_array($page))
            ->keyBy(fn (array $page): string => (string) ($page['id'] ?? ''));
        $result = [];
        foreach ($usages as $usage) {
            $page = $configurationPages->get((string) $usage->page_id);
            if (! is_array($page) || ($page['type'] ?? null) !== WallboardMediaUsageSynchronizer::PAGE_TYPE) {
                continue;
            }
            $duration = (int) (($page['options'] ?? [])['item_duration_seconds'] ?? 0);
            if ($duration < WallboardMediaUsageSynchronizer::MIN_ITEM_DURATION_SECONDS
                || $duration > WallboardMediaUsageSynchronizer::MAX_ITEM_DURATION_SECONDS) {
                continue;
            }
            $items = $usage->mediaPlaylist?->items
                ?->filter(fn ($item): bool => $item->asset?->status === 'ready'
                    && ($item->asset->kind ?: 'image') === 'image')
                ->map(fn ($item): array => [
                    'id' => (string) $item->asset->id,
                    'name' => (string) $item->asset->display_name,
                    'image_url' => '/api/wallboard/media/'.(string) $item->asset->id,
                    'media_asset_version' => (int) $item->asset->version,
                    'width' => (int) $item->asset->width,
                    'height' => (int) $item->asset->height,
                ])
                ->values()
                ->all() ?? [];
            if ($items === []) {
                continue;
            }
            $result[(string) $usage->page_id] = [
                'media_playlist_id' => (string) $usage->media_playlist_id,
                'media_playlist_version' => (int) $usage->mediaPlaylist->version,
                'item_duration_seconds' => $duration,
                'total_duration_seconds' => count($items) * $duration,
                'items' => $items,
            ];
        }

        return $result;
    }

    /**
     * Resolve media for an unsaved administrator preview without creating the
     * usage projections that authorize content on paired wallboards.
     *
     * @param  array<string, mixed>  $resolvedConfiguration
     * @return array{configuration: array<string, mixed>, photo_pages: array<string, array<string, mixed>>}
     */
    public function preview(array $resolvedConfiguration): array
    {
        $configuration = $resolvedConfiguration;
        $photoPages = [];
        $photoReferences = $this->usageSynchronizer->references($configuration);
        $photoIds = $this->caseVariants(array_column($photoReferences, 'media_playlist_id'));
        $playlists = $this->playlists->withItems($photoIds)
            ->keyBy(fn (WallboardMediaPlaylist $playlist): string => strtolower((string) $playlist->id));

        foreach ($photoReferences as $reference) {
            $playlist = $playlists->get(strtolower($reference['media_playlist_id']));
            $items = $playlist instanceof WallboardMediaPlaylist
                ? $playlist->items
                    ->filter(fn ($item): bool => $item->asset?->status === WallboardMediaAsset::STATUS_READY
                        && ($item->asset->kind ?: WallboardMediaAsset::KIND_IMAGE) === WallboardMediaAsset::KIND_IMAGE)
                    ->values()
                : collect();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$reference['page_index']}.options.media_playlist_id" => ['De geselecteerde fotoplaylist heeft geen beschikbare afbeeldingen.'],
                ]);
            }
            if ($items->count() > intdiv(
                WallboardMediaUsageSynchronizer::MAX_PAGE_DURATION_SECONDS,
                $reference['item_duration_seconds'],
            )) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$reference['page_index']}.options.media_playlist_id" => ['De totale duur van een fotocarrousel mag maximaal 3600 seconden zijn.'],
                ]);
            }

            $actualPlaylistId = (string) $playlist->id;
            $totalDuration = $items->count() * $reference['item_duration_seconds'];
            $configuration['pages'][$reference['page_index']]['options']['media_playlist_id'] = $actualPlaylistId;
            $configuration['pages'][$reference['page_index']]['duration_seconds'] = $totalDuration;
            $photoPages[$reference['page_id']] = [
                'media_playlist_id' => $actualPlaylistId,
                'media_playlist_version' => (int) $playlist->version,
                'item_duration_seconds' => $reference['item_duration_seconds'],
                'total_duration_seconds' => $totalDuration,
                'items' => $items->map(fn ($item): array => [
                    'id' => (string) $item->asset->id,
                    'name' => (string) $item->asset->display_name,
                    'image_url' => $this->adminContentUrl(
                        (string) $item->asset->id,
                        (int) $item->asset->version,
                    ),
                    'media_asset_version' => (int) $item->asset->version,
                    'width' => (int) $item->asset->width,
                    'height' => (int) $item->asset->height,
                ])->all(),
            ];
        }

        $videoReferences = $this->usageSynchronizer->videoReferences($configuration);
        $videoIds = $this->caseVariants(array_column($videoReferences, 'media_asset_id'));
        $assets = $this->assets->readyAssets($videoIds)
            ->keyBy(fn (WallboardMediaAsset $asset): string => strtoupper((string) $asset->id));
        foreach ($videoReferences as $reference) {
            $asset = $assets->get(strtoupper($reference['media_asset_id']));
            if (! $asset instanceof WallboardMediaAsset) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$reference['page_index']}.options.media_asset_id" => ['De geselecteerde MP4-video bestaat niet meer of is niet beschikbaar.'],
                ]);
            }
            $duration = $asset->duration_seconds;
            if ($asset->kind !== WallboardMediaAsset::KIND_VIDEO
                || $asset->mime_type !== 'video/mp4'
                || ! is_int($duration)
                || $duration < WallboardConfiguration::MIN_VIDEO_DURATION_SECONDS
                || $duration > WallboardConfiguration::MAX_VIDEO_DURATION_SECONDS) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$reference['page_index']}.options.media_asset_id" => ['Selecteer een beschikbare, volledig gecontroleerde MP4-video.'],
                ]);
            }

            $actualAssetId = (string) $asset->id;
            $configuration['pages'][$reference['page_index']]['options'] = [
                'media_asset_id' => $actualAssetId,
                'media_asset_version' => (int) $asset->version,
                'url' => $this->adminContentUrl($actualAssetId),
                'video_duration_seconds' => $duration,
            ];
            $configuration['pages'][$reference['page_index']]['duration_seconds'] = min(
                3600,
                $duration + WallboardConfiguration::VIDEO_STARTUP_BUFFER_SECONDS,
            );
        }

        return ['configuration' => $configuration, 'photo_pages' => $photoPages];
    }

    /** @param list<string> $ids
     * @return list<string>
     */
    private function caseVariants(array $ids): array
    {
        return collect($ids)
            ->flatMap(static fn (string $id): array => [$id, strtolower($id), strtoupper($id)])
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function adminContentUrl(string $assetId, ?int $version = null): string
    {
        $url = '/api/admin/wallboard-media/assets/'.$assetId.'/content';

        return $version !== null && $version >= 1 ? $url.'?v='.$version : $url;
    }
}
