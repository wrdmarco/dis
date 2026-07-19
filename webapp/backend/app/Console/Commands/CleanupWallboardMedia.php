<?php

namespace App\Console\Commands;

use App\Services\WallboardMediaCleanupService;
use Illuminate\Console\Command;

final class CleanupWallboardMedia extends Command
{
    protected $signature = 'dis:cleanup-wallboard-media';

    protected $description = 'Safely remove expired wallboard-media staging files and unreferenced objects';

    public function handle(WallboardMediaCleanupService $cleanup): int
    {
        $result = $cleanup->cleanup();
        $this->info(sprintf(
            'Wallboard media cleanup complete: %d staging file(s), %d object(s), %d usage projection(s).',
            $result['staging_deleted'],
            $result['objects_deleted'],
            $result['usages_deleted'],
        ));

        return self::SUCCESS;
    }
}
