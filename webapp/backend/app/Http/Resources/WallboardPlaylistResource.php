<?php

namespace App\Http\Resources;

use App\Models\WallboardPlaylist;
use App\Support\ApiDateTime;
use App\Support\WallboardConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WallboardPlaylistResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->id,
            'name' => (string) $this->resource->name,
            'data_mode' => in_array($this->resource->data_mode, WallboardPlaylist::DATA_MODES, true)
                ? (string) $this->resource->data_mode
                : WallboardPlaylist::DATA_MODE_LIVE,
            'purpose' => $this->resource->normalizedPurpose(),
            'configuration' => WallboardConfiguration::normalize((array) $this->resource->configuration),
            'version' => (int) $this->resource->version,
            'linked_wallboards_count' => (int) ($this->resource->wallboards_count ?? 0),
            'created_by' => $this->resource->created_by === null ? null : (string) $this->resource->created_by,
            'updated_by' => $this->resource->updated_by === null ? null : (string) $this->resource->updated_by,
            'created_at' => ApiDateTime::dateTime($this->resource->created_at),
            'updated_at' => ApiDateTime::dateTime($this->resource->updated_at),
        ];
    }
}
