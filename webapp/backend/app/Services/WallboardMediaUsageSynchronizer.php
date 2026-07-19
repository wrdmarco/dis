<?php

namespace App\Services;

use App\Models\WallboardMediaPlaylist;
use App\Models\WallboardMediaPlaylistUsage;
use App\Models\WallboardPlaylist;
use App\Repositories\WallboardMediaPlaylistRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WallboardMediaUsageSynchronizer
{
    public const PAGE_TYPE = 'photo_carousel';

    public const MIN_ITEM_DURATION_SECONDS = 5;

    public const MAX_ITEM_DURATION_SECONDS = 300;

    public const MAX_PAGE_DURATION_SECONDS = 3600;

    public function __construct(private readonly WallboardMediaPlaylistRepository $playlists) {}

    /**
     * The caller must first hold WallboardMediaCoordinationService's shared
     * row lock, then the wallboard playlist lock, and execute this method in
     * the same transaction that persists its JSON configuration.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function synchronize(WallboardPlaylist $wallboardPlaylist, array $configuration): array
    {
        $references = $this->references($configuration);
        $locked = [];
        $playlistIds = array_values(array_unique(array_column($references, 'media_playlist_id')));
        sort($playlistIds, SORT_STRING);
        foreach ($playlistIds as $playlistId) {
            try {
                $playlist = $this->playlists->lockPlaylist($playlistId);
            } catch (ModelNotFoundException) {
                throw ValidationException::withMessages([
                    'configuration.pages' => ['Een geselecteerde fotoplaylist bestaat niet meer.'],
                ]);
            }
            $playlist->load(['items.asset']);
            $locked[$playlistId] = $playlist;
        }

        foreach ($references as $reference) {
            $playlist = $locked[$reference['media_playlist_id']] ?? null;
            $readyItems = $playlist instanceof WallboardMediaPlaylist
                ? $playlist->items->filter(fn ($item): bool => $item->asset?->status === 'ready')->count()
                : 0;
            if ($readyItems < 1) {
                throw ValidationException::withMessages([
                    'configuration.pages' => ['Een fotocarrousel heeft minimaal één beschikbare afbeelding nodig.'],
                ]);
            }
            if ($readyItems > intdiv(self::MAX_PAGE_DURATION_SECONDS, $reference['item_duration_seconds'])) {
                throw ValidationException::withMessages([
                    'configuration.pages' => ['De totale duur van een fotocarrousel mag maximaal 3600 seconden zijn.'],
                ]);
            }
            $configuration['pages'][$reference['page_index']]['duration_seconds'] = $readyItems
                * $reference['item_duration_seconds'];
        }

        WallboardMediaPlaylistUsage::query()
            ->where('wallboard_playlist_id', (string) $wallboardPlaylist->id)
            ->delete();
        $timestamp = now();
        foreach ($references as $reference) {
            WallboardMediaPlaylistUsage::query()->create([
                'wallboard_playlist_id' => (string) $wallboardPlaylist->id,
                'page_id' => $reference['page_id'],
                'media_playlist_id' => $reference['media_playlist_id'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        return $configuration;
    }

    /**
     * Remove the derived projection before deleting its source playlist. The
     * caller must hold the shared coordination lock and playlist row lock.
     */
    public function clear(WallboardPlaylist $wallboardPlaylist): void
    {
        WallboardMediaPlaylistUsage::query()
            ->where('wallboard_playlist_id', (string) $wallboardPlaylist->id)
            ->delete();
    }

    /**
     * Recalculate the stored rotation duration after a referenced media
     * playlist changes. The caller owns the coordination, wallboard-playlist
     * and media-playlist locks and has already validated the ready item count.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function deriveForMediaPlaylist(
        array $configuration,
        string $mediaPlaylistId,
        int $readyItemCount,
    ): array {
        foreach ($this->references($configuration) as $reference) {
            if ($reference['media_playlist_id'] !== $mediaPlaylistId) {
                continue;
            }

            $configuration['pages'][$reference['page_index']]['duration_seconds'] = $readyItemCount
                * $reference['item_duration_seconds'];
        }

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<array{page_index: int, page_id: string, media_playlist_id: string, item_duration_seconds: int}>
     */
    public function references(array $configuration): array
    {
        $references = [];
        foreach ((array) ($configuration['pages'] ?? []) as $index => $page) {
            if (! is_array($page) || ($page['type'] ?? null) !== self::PAGE_TYPE) {
                continue;
            }
            $pageId = trim((string) ($page['id'] ?? ''));
            $options = is_array($page['options'] ?? null) ? $page['options'] : [];
            $playlistId = trim((string) ($options['media_playlist_id'] ?? ''));
            $duration = filter_var($options['item_duration_seconds'] ?? null, FILTER_VALIDATE_INT);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $pageId) !== 1
                || ! Str::isUlid($playlistId)
                || ! is_int($duration)
                || $duration < self::MIN_ITEM_DURATION_SECONDS
                || $duration > self::MAX_ITEM_DURATION_SECONDS) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}" => ['De fotocarrouselconfiguratie is ongeldig.'],
                ]);
            }
            if (isset($references[$pageId])) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.id" => ['Pagina-ID’s moeten uniek zijn.'],
                ]);
            }
            $references[$pageId] = [
                'page_index' => (int) $index,
                'page_id' => $pageId,
                'media_playlist_id' => $playlistId,
                'item_duration_seconds' => $duration,
            ];
        }

        return array_values($references);
    }
}
