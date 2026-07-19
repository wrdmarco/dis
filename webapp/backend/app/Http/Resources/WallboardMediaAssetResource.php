<?php

namespace App\Http\Resources;

use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WallboardMediaAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->id,
            'folder_id' => $this->resource->folder_id === null ? null : (string) $this->resource->folder_id,
            'folder_name' => $this->resource->folder?->name,
            'display_name' => (string) $this->resource->display_name,
            'original_name' => (string) $this->resource->original_name,
            'mime_type' => (string) $this->resource->mime_type,
            'byte_size' => (int) $this->resource->byte_size,
            'width' => (int) $this->resource->width,
            'height' => (int) $this->resource->height,
            'status' => (string) $this->resource->status,
            'version' => (int) $this->resource->version,
            'playlist_references_count' => (int) ($this->resource->playlist_items_count ?? 0),
            'content_url' => $this->resource->status === 'ready'
                ? '/api/admin/wallboard-media/assets/'.(string) $this->resource->id.'/content'
                : null,
            'created_at' => ApiDateTime::dateTime($this->resource->created_at),
            'updated_at' => ApiDateTime::dateTime($this->resource->updated_at),
        ];
    }
}
