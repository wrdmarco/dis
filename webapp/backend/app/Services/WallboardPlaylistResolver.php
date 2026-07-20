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
        return $this->resolveRuntime($wallboard, false)['configuration'];
    }

    /**
     * @return array{configuration: array<string, mixed>, playlist_id: string|null, playlist_version: int, active_incident_playlist: bool}
     */
    public function resolveRuntime(Wallboard $wallboard, bool $deploymentActive): array
    {
        if ($deploymentActive && $wallboard->active_incident_playlist_id !== null) {
            $wallboard->loadMissing('activeIncidentPlaylist');
            $playlist = $wallboard->getRelation('activeIncidentPlaylist');
            if ($playlist instanceof WallboardPlaylist) {
                return $this->result($playlist, true);
            }
        }

        if ($wallboard->playlist_id !== null) {
            $wallboard->loadMissing('playlist');
            $playlist = $wallboard->getRelation('playlist');
            if ($playlist instanceof WallboardPlaylist) {
                return $this->result($playlist, false);
            }
        }

        return [
            'configuration' => WallboardConfiguration::normalize((array) $wallboard->configuration),
            'playlist_id' => null,
            'playlist_version' => 0,
            'active_incident_playlist' => false,
        ];
    }

    /** @return array{configuration: array<string, mixed>, playlist_id: string, playlist_version: int, active_incident_playlist: bool} */
    private function result(WallboardPlaylist $playlist, bool $activeIncidentPlaylist): array
    {
        return [
            'configuration' => WallboardConfiguration::normalize((array) $playlist->configuration),
            'playlist_id' => (string) $playlist->id,
            'playlist_version' => (int) $playlist->version,
            'active_incident_playlist' => $activeIncidentPlaylist,
        ];
    }
}
