<?php

namespace App\Console\Commands;

use App\Services\OperationalRadarCacheWarmupService;
use Illuminate\Console\Command;

final class WarmOperationalRadarCache extends Command
{
    protected $signature = 'dis:warm-operational-radar-cache';

    protected $description = 'Warm the shared operational radar cache with the current frame for each layer';

    public function handle(OperationalRadarCacheWarmupService $warmup): int
    {
        $result = $warmup->warmReferenceFrames();
        $this->info(sprintf(
            'Operational radar cache warmup complete: %d frame(s) requested, %d warmed.',
            $result['requested'],
            $result['warmed'],
        ));

        return self::SUCCESS;
    }
}
