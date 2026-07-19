<?php

namespace App\Services;

use App\Models\Wallboard;
use App\Models\WallboardMediaPlaylistUsage;

final class WallboardMediaStateService
{
    /** @return array<string, array<string, mixed>> */
    /** @param array<string, mixed> $resolvedConfiguration */
    public function pages(Wallboard $wallboard, array $resolvedConfiguration): array
    {
        $wallboardPlaylistId = $wallboard->playlist_id;
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
                ?->filter(fn ($item): bool => $item->asset?->status === 'ready')
                ->map(fn ($item): array => [
                    'id' => (string) $item->asset->id,
                    'name' => (string) $item->asset->display_name,
                    'image_url' => '/api/wallboard/media/'.(string) $item->asset->id,
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
}
