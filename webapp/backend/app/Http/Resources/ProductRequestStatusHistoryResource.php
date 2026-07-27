<?php

namespace App\Http\Resources;

use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProductRequestStatusHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->id,
            'from_status' => $this->resource->from_status,
            'to_status' => (string) $this->resource->to_status,
            'note' => $this->resource->note,
            'changed_by' => [
                'id' => $this->resource->changed_by === null
                    ? null
                    : (string) $this->resource->changed_by,
                'name' => (string) $this->resource->changed_by_name_snapshot,
            ],
            'created_at' => ApiDateTime::dateTime($this->resource->created_at),
        ];
    }
}
