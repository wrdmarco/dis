<?php

namespace App\Services;

use App\Events\AssetChanged;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\DroneType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class AssetService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): Asset
    {
        $data = $this->normalizeDroneType($data);
        $data = $this->ensureAssetTag($data);
        $asset = Asset::query()->create($data);
        $this->auditService->record('assets.created', $asset, $actor);
        $this->broadcastAssetChange($asset, 'created');

        return $asset;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createForUser(array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($data, $actor): Asset {
            $data = $this->normalizeDroneType($data);
            $data = $this->ensureAssetTag($data);
            $asset = Asset::query()->create($data);
            AssetAssignment::query()->create([
                'asset_id' => $asset->id,
                'user_id' => $actor->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            $this->auditService->record('assets.self_created', $asset, $actor);
            $this->broadcastAssetChange($asset, 'self_created');

            return $asset->refresh()->load('assignments');
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Asset $asset, array $data, User $actor): Asset
    {
        $data = $this->normalizeDroneType($data, $asset);
        $before = $asset->only(array_keys($data));
        $asset->update($data);
        $this->auditService->record('assets.updated', $asset, $actor, [
            'before' => $before,
            'after' => $asset->only(array_keys($data)),
        ]);
        $this->broadcastAssetChange($asset->refresh(), 'updated');

        return $asset;
    }

    public function delete(Asset $asset, User $actor): void
    {
        DB::transaction(function () use ($asset, $actor): void {
            $asset->assignments()->whereNull('released_at')->update(['released_at' => now()]);
            $this->auditService->record('assets.deleted', $asset, $actor);
            $asset->delete();
            $this->broadcastAssetChange($asset, 'deleted');
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function assign(Asset $asset, array $data, User $actor): AssetAssignment
    {
        return DB::transaction(function () use ($asset, $data, $actor): AssetAssignment {
            $assignment = AssetAssignment::query()->create($data + ['asset_id' => $asset->id, 'assigned_by' => $actor->id, 'assigned_at' => now()]);
            $asset->update(['status' => 'assigned']);
            $this->auditService->record('assets.assigned', $asset, $actor, $data);
            $this->broadcastAssetChange($asset->refresh(), 'assigned');

            return $assignment;
        });
    }

    private function broadcastAssetChange(Asset $asset, string $action): void
    {
        try {
            AssetChanged::dispatch($asset, $action);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDroneType(array $data, ?Asset $asset = null): array
    {
        $type = $data['type'] ?? $asset?->type;
        if ($type !== 'drone') {
            $data['drone_type_id'] = null;
            $data['has_spotlight'] = false;
            $data['has_speaker'] = false;

            return $data;
        }

        $droneTypeId = $data['drone_type_id'] ?? $asset?->drone_type_id;
        if (! is_string($droneTypeId) || $droneTypeId === '') {
            $data['has_spotlight'] = false;
            $data['has_speaker'] = false;

            return $data;
        }

        $droneType = DroneType::query()->find($droneTypeId);
        if ($droneType !== null) {
            if (! $droneType->has_spotlight) {
                $data['has_spotlight'] = false;
            }
            if (! $droneType->has_speaker) {
                $data['has_speaker'] = false;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function ensureAssetTag(array $data): array
    {
        if (! empty($data['asset_tag'])) {
            return $data;
        }

        do {
            $tag = 'AST-'.Str::upper(Str::random(8));
        } while (Asset::query()->where('asset_tag', $tag)->exists());

        $data['asset_tag'] = $tag;

        return $data;
    }
}
