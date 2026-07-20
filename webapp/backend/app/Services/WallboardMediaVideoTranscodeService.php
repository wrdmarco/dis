<?php

namespace App\Services;

use App\Models\WallboardMediaAsset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class WallboardMediaVideoTranscodeService
{
    public function __construct(
        private readonly WallboardMediaVideoProcessor $processor,
        private readonly WallboardMediaQuotaService $quota,
        private readonly AuditService $auditService,
    ) {}

    /** @param null|callable(): bool $interrupted */
    public function transcode(string $assetId, ?callable $interrupted = null): void
    {
        Cache::lock(
            'wallboard-media:video-transcode:'.$assetId,
            max(300, (int) config('wallboard_media.video_transcode_timeout_seconds', 3600) + 120),
        )->block(1, function () use ($assetId, $interrupted): void {
            $asset = WallboardMediaAsset::query()
                ->whereKey($assetId)
                ->where('kind', WallboardMediaAsset::KIND_VIDEO)
                ->where('status', WallboardMediaAsset::STATUS_PROCESSING)
                ->first();
            if ($asset === null) {
                return;
            }

            $diskName = (string) config('wallboard_media.disk', 'local');
            $disk = Storage::disk($diskName);
            $destination = $disk->path((string) $asset->storage_path);
            $sourceSha256 = @hash_file('sha256', $destination);
            if (! is_string($sourceSha256) || ! hash_equals((string) $asset->sha256, $sourceSha256)) {
                throw new HttpException(503, 'De bronvideo komt niet overeen met de vastgelegde integriteitscontrole.');
            }
            if ($interrupted !== null && $interrupted()) {
                throw new HttpException(503, 'De videoverwerking is veilig onderbroken voor systeemonderhoud.');
            }

            $processed = $this->processor->transcode($destination);
            $backup = $processed->temporaryPath.'.source';
            $replaced = false;
            try {
                $additionalBytes = max(0, $processed->byteSize - (int) $asset->byte_size);
                $this->quota->reserveAdditionalBytes($additionalBytes, function () use (
                    $assetId,
                    $sourceSha256,
                    $destination,
                    $backup,
                    $processed,
                    &$replaced,
                ): void {
                    DB::transaction(function () use (
                        $assetId,
                        $sourceSha256,
                        $destination,
                        $backup,
                        $processed,
                        &$replaced,
                    ): void {
                        $locked = WallboardMediaAsset::query()->whereKey($assetId)->lockForUpdate()->first();
                        if ($locked === null
                            || $locked->kind !== WallboardMediaAsset::KIND_VIDEO
                            || $locked->status !== WallboardMediaAsset::STATUS_PROCESSING) {
                            return;
                        }
                        $currentSha256 = @hash_file('sha256', $destination);
                        if (! is_string($currentSha256)
                            || ! hash_equals($sourceSha256, $currentSha256)
                            || ! hash_equals((string) $locked->sha256, $currentSha256)) {
                            throw new HttpException(503, 'De bronvideo is tijdens de verwerking gewijzigd.');
                        }
                        if (! @link($destination, $backup) && ! @copy($destination, $backup)) {
                            throw new HttpException(503, 'De verwerkte video kon niet atomair worden geactiveerd.');
                        }
                        if (! @rename($processed->temporaryPath, $destination)) {
                            // Windows test hosts cannot replace an existing file
                            // with rename(). The verified backup keeps this
                            // fallback recoverable; production Ubuntu uses the
                            // atomic replace path above.
                            if (! @unlink($destination) || ! @rename($processed->temporaryPath, $destination)) {
                                @rename($backup, $destination);
                                throw new HttpException(503, 'De verwerkte video kon niet atomair worden geactiveerd.');
                            }
                        }
                        $replaced = true;
                        @chmod($destination, 0640);
                        $locked->forceFill([
                            'sha256' => $processed->sha256,
                            'mime_type' => $processed->mimeType,
                            'byte_size' => $processed->byteSize,
                            'width' => $processed->width,
                            'height' => $processed->height,
                            'duration_seconds' => $processed->durationSeconds,
                            'status' => WallboardMediaAsset::STATUS_READY,
                            'version' => (int) $locked->version + 1,
                        ])->save();
                        $this->auditService->record('wallboard_media.assets.video_transcoded', $locked, null, [
                            'source_sha256_prefix' => substr($sourceSha256, 0, 12),
                            'sha256_prefix' => substr($processed->sha256, 0, 12),
                            'byte_size' => $processed->byteSize,
                            'width' => $processed->width,
                            'height' => $processed->height,
                            'duration_seconds' => $processed->durationSeconds,
                            'version' => (int) $locked->version,
                        ]);
                    }, 3);
                });
                @unlink($backup);
            } catch (Throwable $exception) {
                if ($replaced && is_file($backup)) {
                    @rename($backup, $destination);
                }

                throw $exception;
            } finally {
                if (is_file($processed->temporaryPath)) {
                    @unlink($processed->temporaryPath);
                }
                if (is_file($backup)) {
                    @unlink($backup);
                }
            }
        });
    }
}
