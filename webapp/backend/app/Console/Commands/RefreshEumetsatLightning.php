<?php

namespace App\Console\Commands;

use App\Jobs\RefreshEumetsatLightningSnapshot;
use Illuminate\Console\Command;

final class RefreshEumetsatLightning extends Command
{
    protected $signature = 'dis:refresh-eumetsat-lightning';

    protected $description = 'Queue a free EUMETView MTG Lightning Imager atlas refresh';

    public function handle(): int
    {
        RefreshEumetsatLightningSnapshot::dispatch();

        return self::SUCCESS;
    }
}
