<?php

namespace App\Http\Resources;

use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserNotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->id,
            'type' => (string) $this->resource->type,
            'tone' => (string) $this->resource->tone,
            'title' => (string) $this->resource->title,
            'message' => (string) $this->resource->message,
            'action_url' => (string) $this->resource->action_url,
            'occurred_at' => ApiDateTime::dateTime($this->resource->occurred_at),
            'read_at' => ApiDateTime::dateTime($this->resource->read_at),
        ];
    }
}
