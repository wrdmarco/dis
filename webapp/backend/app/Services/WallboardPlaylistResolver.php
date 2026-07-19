<?php

namespace App\Services;

use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Support\WallboardConfiguration;

final class WallboardPlaylistResolver
{
    /** @return array<string, mixed> */
    public function resolve(Wallboard $wallboard): array
    {
        if ($wallboard->playlist_id !== null) {
            $wallboard->loadMissing('playlist');
            $playlist = $wallboard->getRelation('playlist');
            if ($playlist instanceof WallboardPlaylist) {
                return WallboardConfiguration::normalize((array) $playlist->configuration);
            }
        }

        return WallboardConfiguration::normalize((array) $wallboard->configuration);
    }
}
