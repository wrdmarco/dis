<?php

namespace App\Jobs;

use App\Services\SpeechPreparedPhraseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RequeueSpeechPreparedPhrases implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 300;

    public function __construct(public readonly bool $force = true)
    {
        $this->onConnection('redis')->onQueue('speech');
    }

    public function handle(SpeechPreparedPhraseService $service): void
    {
        if ($this->force) {
            $service->requeueAll();

            return;
        }
        $service->requeueStale();
    }
}
