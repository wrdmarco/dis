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
            ->with([
                'playlist:id,name,data_mode,purpose,configuration,version',
                'activeIncidentPlaylist:id,name,data_mode,purpose,configuration,version',
                'nonRevokedSessions:id,wallboard_id,last_seen_at,expires_at',
            ])
            ->orderBy('name')
            ->get();
    }

    public function findForManagement(string $id): Wallboard
    {
        return Wallboard::query()
            ->with([
                'playlist:id,name,data_mode,purpose,configuration,version',
                'activeIncidentPlaylist:id,name,data_mode,purpose,configuration,version',
                'nonRevokedSessions:id,wallboard_id,last_seen_at,expires_at',
            ])
            ->findOrFail($id);
    }

    public function lockWallboard(string $id): Wallboard
    {
        return Wallboard::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function playlistId(string $id): ?string
    {
        $playlistId = Wallboard::query()->whereKey($id)->value('playlist_id');

        return $playlistId === null ? null : (string) $playlistId;
    }

    public function lockWallboardOrNull(string $id): ?Wallboard
    {
        return Wallboard::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function lockSession(string $id): ?WallboardSession
    {
        return WallboardSession::query()
            ->with(['wallboard.playlist', 'wallboard.activeIncidentPlaylist'])
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
    }
}
