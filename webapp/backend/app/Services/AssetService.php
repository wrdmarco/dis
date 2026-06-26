<?php

namespace App\Services;

use App\Events\AssetChanged;
use App\Models\Asset;
use App\Models\AssetAssignment;
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
        $data = $this->normalizeDroneType($data);
        $before = $asset->only(array_keys($data));
        $asset->update($data);
        $this->auditService->record('assets.updated', $asset, $actor, [
            'before' => $before,
            'after' => $asset->only(array_keys($data)),
        ]);
        $this->broadcastAssetChange($asset->refresh(), 'updated');

        return $asset;
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
    private function normalizeDroneType(array $data): array
    {
        if (($data['type'] ?? null) !== 'drone') {
            $data['drone_type_id'] = null;
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
