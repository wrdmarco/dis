<?php

namespace App\Repositories;

use App\Models\Wallboard;
use App\Models\WallboardSession;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<Wallboard>
 */
final class WallboardRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return Wallboard::class;
    }

    /** @return Collection<int, Wallboard> */
    public function allForManagement(): Collection
    {
        return Wallboard::query()
            ->withCount(['sessions as active_sessions_count' => fn ($query) => $query
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())])
            ->orderBy('name')
            ->get();
    }

    public function findForManagement(string $id): Wallboard
    {
        return Wallboard::query()
            ->withCount(['sessions as active_sessions_count' => fn ($query) => $query
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())])
            ->findOrFail($id);
    }

    public function lockWallboard(string $id): Wallboard
    {
        return Wallboard::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function lockWallboardOrNull(string $id): ?Wallboard
    {
        return Wallboard::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function lockSession(string $id): ?WallboardSession
    {
        return WallboardSession::query()
            ->with('wallboard')
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
    }
}
