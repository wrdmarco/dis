<?php

namespace App\Http\Resources;

use App\Models\ProductRequest;
use App\Models\User;
use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProductRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ProductRequest $productRequest */
        $productRequest = $this->resource;
        $actor = $request->user();
        if (! $actor instanceof User) {
            $actor = null;
        }
        $resolver = $productRequest->relationLoaded('resolver')
            ? $productRequest->resolver
            : null;
        $mayUpdateOwn = $this->hasPermission(
            $request,
            $actor,
            'product-requests.update-own',
        );
        $mayResolve = $this->hasPermission(
            $request,
            $actor,
            'product-requests.resolve',
        );

        $payload = [
            'id' => (string) $productRequest->id,
            'type' => (string) $productRequest->type,
            'title' => (string) $productRequest->title,
            'description' => (string) $productRequest->description,
            'status' => (string) $productRequest->status,
            'resolution_note' => $productRequest->resolution_note,
            'requester' => [
                'id' => $productRequest->requester_id === null
                    ? null
                    : (string) $productRequest->requester_id,
                'name' => (string) $productRequest->requester_name_snapshot,
            ],
            'resolved_by' => $productRequest->resolved_by === null
                ? null
                : [
                    'id' => (string) $productRequest->resolved_by,
                    'name' => $resolver?->name,
                ],
            'resolved_at' => ApiDateTime::dateTime($productRequest->resolved_at),
            'lock_version' => (int) $productRequest->lock_version,
            'is_owner' => $actor !== null && $productRequest->isOwnedBy($actor),
            'can_update' => $mayUpdateOwn
                && $actor !== null
                && $productRequest->isOwnedBy($actor)
                && ! $productRequest->isTerminal(),
            'can_resolve' => $mayResolve,
            'created_at' => ApiDateTime::dateTime($productRequest->created_at),
            'updated_at' => ApiDateTime::dateTime($productRequest->updated_at),
        ];

        if ($productRequest->relationLoaded('statusHistories')) {
            $payload['status_history'] = $productRequest->statusHistories
                ->map(fn ($history): array => (new ProductRequestStatusHistoryResource($history))
                    ->resolve($request))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function hasPermission(
        Request $request,
        ?User $actor,
        string $permission,
    ): bool {
        if ($actor === null) {
            return false;
        }

        $cacheKey = 'product_request_permission:'.$permission;
        if (! $request->attributes->has($cacheKey)) {
            $request->attributes->set($cacheKey, $actor->hasPermission($permission));
        }

        return $request->attributes->getBoolean($cacheKey);
    }
}
