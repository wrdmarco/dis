<?php

namespace App\Http\Resources;

use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WallboardMediaFolderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->id,
            'parent_id' => $this->resource->parent_id === null ? null : (string) $this->resource->parent_id,
            'name' => (string) $this->resource->name,
            'version' => (int) $this->resource->version,
            'children_count' => (int) ($this->resource->children_count ?? 0),
            'assets_count' => (int) ($this->resource->assets_count ?? 0),
            'created_at' => ApiDateTime::dateTime($this->resource->created_at),
            'updated_at' => ApiDateTime::dateTime($this->resource->updated_at),
        ];
    }
}
