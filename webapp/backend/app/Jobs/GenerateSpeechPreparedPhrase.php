<?php

namespace App\Jobs;

use App\Services\SpeechPreparedPhraseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

final class GenerateSpeechPreparedPhrase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 64_800;

    public function __construct(
        public readonly string $phraseId,
        public readonly bool $forceRegeneration = false,
    ) {
        $this->onConnection('redis')->onQueue('speech');
    }

    public function handle(SpeechPreparedPhraseService $service): void
    {
        try {
            $service->prepare($this->phraseId, $this->forceRegeneration);
        } catch (ValidationException $exception) {
            $service->fail($this->phraseId, $service->errorCode($exception));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $service = app(SpeechPreparedPhraseService::class);
        $service->fail(
            $this->phraseId,
            $exception === null ? 'prepared_phrase_worker_failed' : $service->errorCode($exception),
        );
    }
}
