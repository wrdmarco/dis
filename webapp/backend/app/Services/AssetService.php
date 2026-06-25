<?php

namespace App\Services;

use App\Events\AssetChanged;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AssetService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): Asset
    {
        $asset = Asset::query()->create($data);
        $this->auditService->record('assets.created', $asset, $actor);
        AssetChanged::dispatch($asset, 'created');

        return $asset;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(Asset $asset, array $data, User $actor): Asset
    {
        $asset->update($data);
        $this->auditService->record('assets.updated', $asset, $actor);
        AssetChanged::dispatch($asset->refresh(), 'updated');

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
            AssetChanged::dispatch($asset->refresh(), 'assigned');

            return $assignment;
        });
    }
}
