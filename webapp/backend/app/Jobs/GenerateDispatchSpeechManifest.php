<?php

namespace App\Jobs;

use App\Exceptions\SpeechEngineException;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestBuild;
use App\Services\SpeechDispatchGateService;
use App\Services\SpeechManifestGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class GenerateDispatchSpeechManifest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 64_800;

    public function __construct(public readonly string $buildId)
    {
        $this->onConnection('redis')->onQueue('speech');
    }

    public function handle(SpeechManifestGenerationService $generator, SpeechDispatchGateService $gate): void
    {
        $build = SpeechManifestBuild::query()->find($this->buildId);
        if ($build === null) {
            return;
        }
        if ($build->status === 'ready') {
            $manifest = SpeechManifest::query()->where('speech_manifest_build_id', $build->id)->first();
            if ($manifest !== null) {
                $gate->releaseReady($build, $manifest);
            }

            return;
        }
        if (! in_array($build->status, ['queued', 'processing'], true)) {
            return;
        }
        try {
            $manifest = $generator->generate($build);
            $gate->releaseReady($build->refresh(), $manifest);
        } catch (Throwable $exception) {
            $build->refresh()->forceFill([
                'status' => 'failed',
                'error_code' => $exception instanceof SpeechEngineException ? $exception->errorCode : 'manifest_generation_failed',
                'failed_at' => now(),
            ])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        SpeechManifestBuild::query()->whereKey($this->buildId)
            ->where('status', '!=', 'ready')->update([
                'status' => 'failed', 'error_code' => 'manifest_worker_failed',
                'failed_at' => now(), 'updated_at' => now(),
            ]);
    }
}
