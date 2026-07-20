<?php

namespace App\Repositories;

use App\Models\WallboardContentSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class WallboardContentSnapshotRepository
{
    public function find(string $playlistId, string $kind): ?WallboardContentSnapshot
    {
        return WallboardContentSnapshot::query()
            ->where('playlist_id', $playlistId)
            ->where('kind', $kind)
            ->first();
    }

    public function lock(string $playlistId, string $kind): ?WallboardContentSnapshot
    {
        return WallboardContentSnapshot::query()
            ->where('playlist_id', $playlistId)
            ->where('kind', $kind)
            ->lockForUpdate()
            ->first();
    }

    /** @return Collection<int, WallboardContentSnapshot> */
    public function forPlaylist(string $playlistId): Collection
    {
        return WallboardContentSnapshot::query()
            ->where('playlist_id', $playlistId)
            ->whereIn('kind', WallboardContentSnapshot::KINDS)
            ->get();
    }

    /** @param array<string, mixed> $payload */
    public function insert(
        string $playlistId,
        string $kind,
        string $configFingerprint,
        int $revision,
        array $payload,
        CarbonImmutable $checkedAt,
        CarbonImmutable $updatedAt,
    ): void {
        DB::table('wallboard_content_snapshots')->insert([
            'playlist_id' => $playlistId,
            'kind' => $kind,
            'config_fingerprint' => $configFingerprint,
            'revision' => $revision,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'checked_at' => $checkedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    /** @param array<string, mixed> $changes */
    public function update(string $playlistId, string $kind, array $changes): void
    {
        if (array_key_exists('payload', $changes)) {
            $changes['payload'] = json_encode($changes['payload'], JSON_THROW_ON_ERROR);
        }

        DB::table('wallboard_content_snapshots')
            ->where('playlist_id', $playlistId)
            ->where('kind', $kind)
            ->update($changes);
    }

    public function markChecked(string $playlistId, string $kind, CarbonImmutable $checkedAt): void
    {
        DB::table('wallboard_content_snapshots')
            ->where('playlist_id', $playlistId)
            ->where('kind', $kind)
            ->update(['checked_at' => $checkedAt]);
    }

    public function deleteForPlaylist(string $playlistId): void
    {
        DB::table('wallboard_content_snapshots')
            ->where('playlist_id', $playlistId)
            ->delete();
    }
}
