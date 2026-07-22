<?php

namespace App\Console\Commands;

use App\Services\SpeechCachePruner;
use Illuminate\Console\Command;

final class PruneSpeechCache extends Command
{
    protected $signature = 'dis:prune-speech-cache';

    protected $description = 'Prune expired and least-recently-used generated speech audio within the hard quota.';

    public function handle(SpeechCachePruner $pruner): int
    {
        $pruner->pruneExpiredAndQuota();

        return self::SUCCESS;
    }
}
