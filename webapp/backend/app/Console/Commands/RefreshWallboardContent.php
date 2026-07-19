<?php

namespace App\Console\Commands;

use App\Services\WallboardContentSnapshotService;
use Illuminate\Console\Command;

final class RefreshWallboardContent extends Command
{
    protected $signature = 'dis:refresh-wallboard-content';

    protected $description = 'Refresh durable sanitized wallboard news and ticker snapshots';

    public function handle(WallboardContentSnapshotService $snapshots): int
    {
        $result = $snapshots->refreshAll();
        $this->info(sprintf(
            'Wallboard content refresh complete: %d playlist(s), %d snapshot(s), %d failure(s).',
            $result['playlists'],
            $result['snapshots'],
            $result['failures'],
        ));

        return $result['failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
