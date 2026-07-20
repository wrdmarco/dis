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
     * @return array{configuration: array<string, mixed>, playlist_id: string|null, playlist_version: int, active_incident_playlist: bool, data_mode: string}
     */
    public function resolveRuntime(Wallboard $wallboard, bool $deploymentActive): array
    {
        $basePlaylist = null;
        if ($wallboard->playlist_id !== null) {
            $wallboard->loadMissing('playlist');
            $candidate = $wallboard->getRelation('playlist');
            if ($candidate instanceof WallboardPlaylist) {
                $basePlaylist = $candidate;
                if ($this->dataMode($basePlaylist) === WallboardPlaylist::DATA_MODE_DEMO) {
                    return $this->result($basePlaylist, false);
                }
            }
        }

        if ($deploymentActive && $wallboard->active_incident_playlist_id !== null) {
            $wallboard->loadMissing('activeIncidentPlaylist');
            $playlist = $wallboard->getRelation('activeIncidentPlaylist');
            if ($playlist instanceof WallboardPlaylist
                && $this->dataMode($playlist) === WallboardPlaylist::DATA_MODE_LIVE) {
                return $this->result($playlist, true);
            }
        }

        if ($basePlaylist instanceof WallboardPlaylist) {
            return $this->result($basePlaylist, false);
        }

        return [
            'configuration' => WallboardConfiguration::normalize((array) $wallboard->configuration),
            'playlist_id' => null,
            'playlist_version' => 0,
            'active_incident_playlist' => false,
            'data_mode' => WallboardPlaylist::DATA_MODE_LIVE,
        ];
    }

    /** @return array{configuration: array<string, mixed>, playlist_id: string, playlist_version: int, active_incident_playlist: bool, data_mode: string} */
    private function result(WallboardPlaylist $playlist, bool $activeIncidentPlaylist): array
    {
        return [
            'configuration' => WallboardConfiguration::normalize((array) $playlist->configuration),
            'playlist_id' => (string) $playlist->id,
            'playlist_version' => (int) $playlist->version,
            'active_incident_playlist' => $activeIncidentPlaylist,
            'data_mode' => $this->dataMode($playlist),
        ];
    }

    private function dataMode(WallboardPlaylist $playlist): string
    {
        return in_array($playlist->data_mode, WallboardPlaylist::DATA_MODES, true)
            ? (string) $playlist->data_mode
            : WallboardPlaylist::DATA_MODE_LIVE;
    }
}
