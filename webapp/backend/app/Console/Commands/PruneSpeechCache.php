<?php

namespace App\Console\Commands;

use App\Services\SpeechAudioAssetGarbageCollector;
use App\Services\SpeechCachePruner;
use App\Services\SpeechPreparedPhraseService;
use Illuminate\Console\Command;

final class PruneSpeechCache extends Command
{
    protected $signature = 'dis:prune-speech-cache';

    protected $description = 'Prune expired and least-recently-used generated speech audio within the hard quota.';

    public function handle(
        SpeechCachePruner $pruner,
        SpeechPreparedPhraseService $preparedPhrases,
        SpeechAudioAssetGarbageCollector $garbageCollector,
    ): int {
        $garbageCollector->collectExpired();
        $pruner->pruneExpiredAndQuota();
        $preparedPhrases->requeueStale();

        return self::SUCCESS;
    }
}
