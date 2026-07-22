<?php

namespace App\Jobs;

use App\Exceptions\SpeechEngineException;
use App\Models\SpeechCacheJob;
use App\Services\SpeechCacheMaintenanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RegenerateSpeechCache implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 64_800;

    public function __construct(public readonly string $cacheJobId)
    {
        $this->onConnection('redis')->onQueue('speech');
    }

    public function handle(SpeechCacheMaintenanceService $maintenance): void
    {
        $job = SpeechCacheJob::query()->find($this->cacheJobId);
        if ($job === null || ! in_array($job->status, ['queued', 'processing'], true)) {
            return;
        }
        try {
            $maintenance->run($job);
        } catch (Throwable $exception) {
            $job->refresh()->forceFill([
                'status' => 'failed',
                'error_code' => $exception instanceof SpeechEngineException ? $exception->errorCode : 'cache_regeneration_failed',
                'finished_at' => now(),
            ])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        SpeechCacheJob::query()->whereKey($this->cacheJobId)
            ->where('status', '!=', 'ready')->update([
                'status' => 'failed', 'error_code' => 'cache_worker_failed',
                'finished_at' => now(), 'updated_at' => now(),
            ]);
    }
}
