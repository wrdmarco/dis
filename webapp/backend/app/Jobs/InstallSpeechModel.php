<?php

namespace App\Jobs;

use App\Contracts\SpeechEngineClient;
use App\Exceptions\SpeechEngineException;
use App\Models\SpeechModelInstallation;
use App\Services\SpeechModelCatalog;
use App\Services\SpeechRuntimeActivityGate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

final class InstallSpeechModel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 15_000;

    public function __construct(public readonly string $installationId)
    {
        $this->onConnection('redis')->onQueue('speech');
    }

    public function handle(
        SpeechEngineClient $engine,
        SpeechModelCatalog $catalog,
        SpeechRuntimeActivityGate $runtime,
    ): void {
        $claimToken = (string) Str::ulid();
        if (! $runtime->claim($this->installationId, $claimToken)) {
            return;
        }
        $modelId = null;
        try {
            $installation = SpeechModelInstallation::query()->findOrFail($this->installationId);
            $definition = $catalog->model((string) $installation->catalog_key);
            $modelId = (string) $installation->catalog_key;
            $result = $engine->install($modelId, $definition + [
                'revision' => (string) $installation->revision,
                'weights_sha256' => (string) $installation->weights_sha256,
            ]);
            $deadline = microtime(true) + (int) config('dis.speech.install_timeout_seconds', 14_400);
            while (true) {
                if (! $runtime->claimIsActive($this->installationId, $claimToken)) {
                    $this->cancelPreemptedInstall($engine, $modelId);
                    $runtime->releasePreemptedClaim($this->installationId, $claimToken);

                    return;
                }

                $status = is_string($result['status'] ?? null) ? $result['status'] : null;
                if ($status === 'installed') {
                    $revision = $result['installed_revision'] ?? $result['revision'] ?? null;
                    if (! is_string($revision) || $revision !== $installation->revision) {
                        throw new SpeechEngineException('installed_revision_mismatch');
                    }
                    $checksum = $result['weights_sha256'] ?? null;
                    if (! is_string($checksum)
                        || ! hash_equals((string) $installation->weights_sha256, $checksum)) {
                        throw new SpeechEngineException('installed_checksum_mismatch');
                    }
                    $runtime->complete($this->installationId, $claimToken);

                    return;
                }
                if (in_array($status, ['installing', 'downloading', 'processing'], true)) {
                    $progress = is_numeric($result['progress_percent'] ?? null)
                        ? max(0, min(99, (int) $result['progress_percent']))
                        : (int) $installation->progress_percent;
                    $runtime->progress($this->installationId, $claimToken, $progress);
                    if (microtime(true) >= $deadline) {
                        throw new SpeechEngineException('model_install_timeout');
                    }
                    sleep(max(1, (int) config('dis.speech.install_poll_interval_seconds', 2)));
                    $result = $engine->status($modelId);

                    continue;
                }
                if ($status === 'failed') {
                    $errorCode = is_string($result['error_code'] ?? null)
                        ? $result['error_code']
                        : 'model_install_failed';
                    throw new SpeechEngineException($errorCode);
                }

                throw new SpeechEngineException('invalid_model_install_status');
            }
        } catch (Throwable $exception) {
            if (is_string($modelId)) {
                $this->cancelPreemptedInstall($engine, $modelId);
            }
            $code = $exception instanceof SpeechEngineException ? $exception->errorCode : 'model_install_failed';
            $runtime->fail($this->installationId, $claimToken, $code);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(SpeechRuntimeActivityGate::class)->failWorker($this->installationId);
    }

    private function cancelPreemptedInstall(SpeechEngineClient $engine, string $modelId): void
    {
        try {
            $engine->cancelInstall($modelId);
        } catch (Throwable) {
            // The committed alarm path already issued the same idempotent
            // cancellation. Never turn a daemon error into an alarm failure.
        }
    }
}
