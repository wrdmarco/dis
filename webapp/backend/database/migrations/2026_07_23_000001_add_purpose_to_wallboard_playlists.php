<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallboard_playlists', function (Blueprint $table): void {
            $table->enum('purpose', ['normal', 'alarm'])
                ->default('normal')
                ->after('data_mode')
                ->index();
        });

        DB::transaction(function (): void {
            $activePlaylistIds = DB::table('wallboards')
                ->whereNotNull('active_incident_playlist_id')
                ->distinct()
                ->orderBy('active_incident_playlist_id')
                ->pluck('active_incident_playlist_id');

            foreach ($activePlaylistIds as $activePlaylistId) {
                $playlistId = (string) $activePlaylistId;
                $usedAsNormalPlaylist = DB::table('wallboards')
                    ->where('playlist_id', $playlistId)
                    ->exists();

                if (! $usedAsNormalPlaylist) {
                    DB::table('wallboard_playlists')
                        ->where('id', $playlistId)
                        ->update(['purpose' => 'alarm']);

                    continue;
                }

                $source = DB::table('wallboard_playlists')->where('id', $playlistId)->first();
                if ($source === null) {
                    continue;
                }

                // A playlist may historically have been assigned both as the
                // normal rotation and as the active-deployment rotation. Keep
                // that original row and all normal assignments untouched, then
                // clone it once for the alarm relation. Reclassifying it in
                // place would silently invalidate the normal assignment.
                $alarmPlaylistId = (string) Str::ulid();
                $clone = (array) $source;
                $clone['id'] = $alarmPlaylistId;
                $clone['name'] = Str::limit((string) $source->name, 112, '').' - Alarm';
                $clone['purpose'] = 'alarm';
                DB::table('wallboard_playlists')->insert($clone);

                $this->copyProjectionRows(
                    'wallboard_media_playlist_usages',
                    'wallboard_playlist_id',
                    $playlistId,
                    $alarmPlaylistId,
                );
                $this->copyProjectionRows(
                    'wallboard_media_asset_usages',
                    'wallboard_playlist_id',
                    $playlistId,
                    $alarmPlaylistId,
                );
                $this->copyProjectionRows(
                    'wallboard_content_snapshots',
                    'playlist_id',
                    $playlistId,
                    $alarmPlaylistId,
                );

                DB::table('wallboards')
                    ->where('active_incident_playlist_id', $playlistId)
                    ->update(['active_incident_playlist_id' => $alarmPlaylistId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('wallboard_playlists', function (Blueprint $table): void {
            $table->dropIndex(['purpose']);
            $table->dropColumn('purpose');
        });

        // Alarm clones deliberately remain on rollback. They contain real
        // administrator configuration and may have changed after migration;
        // merging or deleting them would be destructive. Older code can use
        // the preserved active_incident_playlist_id relation unchanged.
    }

    private function copyProjectionRows(
        string $table,
        string $playlistColumn,
        string $sourcePlaylistId,
        string $targetPlaylistId,
    ): void {
        if (! Schema::hasTable($table)) {
            return;
        }

        $rows = DB::table($table)
            ->where($playlistColumn, $sourcePlaylistId)
            ->get()
            ->map(static function (object $row) use ($playlistColumn, $targetPlaylistId): array {
                $copy = (array) $row;
                $copy[$playlistColumn] = $targetPlaylistId;

                return $copy;
            })
            ->all();

        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }
    }
};
