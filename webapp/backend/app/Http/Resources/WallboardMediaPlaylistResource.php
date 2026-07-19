<?php

namespace App\Http\Resources;

use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WallboardMediaPlaylistResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $items = $this->resource->items
            ->filter(fn ($item): bool => $item->asset !== null)
            ->map(fn ($item): array => [
                'id' => (string) $item->id,
                'position' => (int) $item->position,
                'asset' => (new WallboardMediaAssetResource($item->asset))->resolve($request),
            ])
            ->values()
            ->all();

        return [
            'id' => (string) $this->resource->id,
            'name' => (string) $this->resource->name,
            'version' => (int) $this->resource->version,
            'usage_count' => (int) ($this->resource->usages_count ?? 0),
            'item_count' => count($items),
            'items' => $items,
            'created_at' => ApiDateTime::dateTime($this->resource->created_at),
            'updated_at' => ApiDateTime::dateTime($this->resource->updated_at),
        ];
    }
}
