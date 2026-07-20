<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Support\WallboardConfiguration;
use Illuminate\Database\Eloquent\Collection;

final class WallboardPlaylistSynchronizer
{
    /**
     * @param  Collection<int, Wallboard>  $wallboards
     * @param  array<string, mixed>  $configuration
     */
    public function updatePlaylistAndLinkedWallboards(
        WallboardPlaylist $playlist,
        Collection $wallboards,
        array $configuration,
        User $actor,
        ?string $dataMode = null,
    ): void {
        $changes = [
            'configuration' => $configuration,
            'version' => (int) $playlist->version + 1,
            'updated_by' => $actor->id,
        ];
        if ($dataMode !== null) {
            $changes['data_mode'] = $dataMode;
        }
        $playlist->forceFill($changes)->save();

        foreach ($wallboards as $wallboard) {
            $this->copyConfigurationToWallboard($wallboard, $playlist, $configuration, $actor, true);
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function copyConfigurationToWallboard(
        Wallboard $wallboard,
        WallboardPlaylist $playlist,
        array $configuration,
        User $actor,
        bool $incrementVersions,
    ): void {
        $changes = [
            'playlist_id' => $playlist->id,
            'configuration' => $configuration,
            'updated_by' => $actor->id,
        ];

        if ($incrementVersions) {
            $changes['config_version'] = (int) $wallboard->config_version + 1;
            $changes['control_version'] = (int) $wallboard->control_version + 1;
            $changes['rotation_started_at'] = now();
        }

        if ($wallboard->manual_page_id !== null
            && ! WallboardConfiguration::hasPage($configuration, (string) $wallboard->manual_page_id)) {
            $changes['manual_page_id'] = null;
            $changes['manual_page_set_at'] = null;
        }

        $wallboard->forceFill($changes)->save();
        $wallboard->setRelation('playlist', $playlist);
    }
}
