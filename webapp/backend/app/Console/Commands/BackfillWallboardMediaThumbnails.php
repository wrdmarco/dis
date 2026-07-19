<?php

namespace App\Console\Commands;

use App\Services\WallboardMediaThumbnailBackfillService;
use Illuminate\Console\Command;

final class BackfillWallboardMediaThumbnails extends Command
{
    protected $signature = 'dis:backfill-wallboard-media-thumbnails {--batch= : Maximum number of ready images to inspect}';

    protected $description = 'Safely create missing thumbnails for existing wallboard images';

    public function handle(WallboardMediaThumbnailBackfillService $backfill): int
    {
        $batchOption = $this->option('batch');
        if ($batchOption !== null
            && (! is_string($batchOption) || preg_match('/^[1-9][0-9]{0,2}$/', $batchOption) !== 1)) {
            $this->error('The --batch option must be an integer from 1 through 100.');

            return self::INVALID;
        }

        $result = $backfill->backfill($batchOption === null ? null : (int) $batchOption);
        if ($result['locked']) {
            $this->info('Wallboard thumbnail backfill is already running.');

            return self::SUCCESS;
        }
        $this->info(sprintf(
            'Wallboard thumbnail backfill: %d scanned, %d created, %d unchanged, %d skipped, %d failed.',
            $result['scanned'],
            $result['backfilled'],
            $result['unchanged'],
            $result['skipped'],
            $result['failures'],
        ));

        return $result['failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
