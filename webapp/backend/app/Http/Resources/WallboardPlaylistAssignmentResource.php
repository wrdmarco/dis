<?php

namespace App\Http\Resources;

use App\Models\WallboardPlaylist;
use App\Support\WallboardConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WallboardPlaylistAssignmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'wallboard_id' => (string) $this->resource->id,
            'playlist_id' => $this->resource->playlist_id === null ? null : (string) $this->resource->playlist_id,
            'data_mode' => $this->resource->playlist instanceof WallboardPlaylist
                && in_array($this->resource->playlist->data_mode, WallboardPlaylist::DATA_MODES, true)
                    ? (string) $this->resource->playlist->data_mode
                    : WallboardPlaylist::DATA_MODE_LIVE,
            'display_profile' => (string) $this->resource->display_profile,
            'configuration' => WallboardConfiguration::normalize((array) $this->resource->configuration),
            'config_version' => (int) $this->resource->config_version,
            'control_version' => (int) $this->resource->control_version,
        ];
    }
}
