<?php

namespace App\Jobs;

use App\Exceptions\SpeechEngineException;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechPreview;
use App\Services\SpeechManifestGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class GenerateSpeechPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 64_800;

    public function __construct(public readonly string $previewId)
    {
        $this->onConnection('redis')->onQueue('speech');
    }

    public function handle(
        SpeechManifestGenerationService $generator,
    ): void {
        $preview = SpeechPreview::query()->find($this->previewId);
        if ($preview === null || ! in_array($preview->status, ['queued', 'processing'], true) || $preview->expires_at?->isPast()) {
            return;
        }
        $build = SpeechManifestBuild::query()->with(['modelInstallation', 'voiceProfile'])->find($preview->speech_manifest_build_id);
        if ($build === null || $build->modelInstallation === null
            || ($build->voice_profile_id !== null && $build->voiceProfile === null)) {
            $this->failPreview($preview, $build, 'speech_configuration_missing');

            return;
        }
        try {
            $preview->forceFill(['status' => 'processing', 'progress_percent' => 5, 'error_code' => null])->save();
            $manifest = $generator->generate($build, function (int $progress) use ($preview): void {
                $preview->forceFill(['progress_percent' => $progress])->save();
            });
            $preview->forceFill([
                'status' => 'ready', 'progress_percent' => 100, 'speech_manifest_id' => $manifest->id,
                'audio_asset_id' => $manifest->audio_asset_id, 'ready_at' => now(), 'error_code' => null,
            ])->save();
        } catch (Throwable $exception) {
            $code = $exception instanceof SpeechEngineException ? $exception->errorCode : 'preview_generation_failed';
            $this->failPreview($preview, $build, $code);
        }
    }

    private function failPreview(SpeechPreview $preview, ?SpeechManifestBuild $build, string $errorCode): void
    {
        $preview->forceFill([
            'status' => 'failed', 'error_code' => $errorCode, 'failed_at' => now(),
        ])->save();
        $build?->forceFill([
            'status' => 'failed', 'error_code' => $errorCode, 'failed_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $preview = SpeechPreview::query()->find($this->previewId);
        if ($preview !== null && $preview->status !== 'ready') {
            $build = SpeechManifestBuild::query()->find($preview->speech_manifest_build_id);
            $this->failPreview($preview, $build, 'preview_worker_failed');
        }
    }
}
