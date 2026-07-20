<?php

namespace App\Jobs;

use App\Models\WallboardMediaAsset;
use App\Services\AuditService;
use App\Services\WallboardMediaVideoTranscodeService;
use Illuminate\Contracts\Queue\Interruptible;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class TranscodeWallboardMediaVideo implements Interruptible, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 3660;

    private bool $interrupted = false;

    public function __construct(public readonly string $assetId)
    {
        $this->onConnection('wallboard_media');
        $this->onQueue('wallboard-media');
    }

    public function handle(WallboardMediaVideoTranscodeService $transcoder): void
    {
        try {
            $transcoder->transcode($this->assetId, fn (): bool => $this->interrupted);
        } catch (Throwable $exception) {
            if (! $this->interrupted) {
                throw $exception;
            }

            // systemd deliberately terminates the worker process group during
            // deployment so ffmpeg cannot extend maintenance for an hour. Push
            // the replacement before deleting the reserved job: a crash between
            // those operations can duplicate safe idempotent work, but cannot
            // lose the transcode or strand it for Redis' long retry_after lease.
            self::dispatch($this->assetId)->delay($this->backoff);
            $this->delete();
        }
    }

    public function interrupted(int $signal): void
    {
        // Laravel invokes this contract only for SIGQUIT, SIGTERM and SIGINT.
        // Keep the signal handler side-effect free; Redis work is performed once
        // the terminated ffmpeg child has returned control to handle().
        $this->interrupted = true;
    }

    public function failed(?Throwable $exception): void
    {
        $asset = WallboardMediaAsset::query()
            ->whereKey($this->assetId)
            ->where('kind', WallboardMediaAsset::KIND_VIDEO)
            ->where('status', WallboardMediaAsset::STATUS_PROCESSING)
            ->first();
        if ($asset === null) {
            return;
        }
        $asset->forceFill([
            'status' => WallboardMediaAsset::STATUS_FAILED,
            'version' => (int) $asset->version + 1,
        ])->save();
        app(AuditService::class)->record('wallboard_media.assets.video_transcode_failed', $asset, null, [
            'byte_size' => (int) $asset->byte_size,
            'version' => (int) $asset->version,
        ]);
        if ($exception !== null) {
            report($exception);
        }
    }
}
