<?php

namespace Tests\Feature;

use App\Http\Responses\WallboardMediaResponse;
use App\Jobs\TranscodeWallboardMediaVideo;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WallboardMediaAsset;
use App\Repositories\WallboardMediaAssetRepository;
use App\Services\WallboardMediaAssetService;
use App\Services\WallboardMediaDeliveryService;
use App\Services\WallboardMediaPlaylistService;
use App\Services\WallboardMediaVideoProcessor;
use App\Services\WallboardMediaVideoTranscodeService;
use App\Support\WallboardMediaContent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class WallboardMediaVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('wallboard_media.disk', 'local');
        config()->set('wallboard_media.minimum_free_bytes', 0);
        config()->set('wallboard_media.max_total_bytes', 1024 * 1024 * 1024);
        Process::fake(fn (PendingProcess $process) => $this->probeResult($process, 1280, 720));
    }

    public function test_valid_fast_start_mp4_is_verified_and_stored_with_duration(): void
    {
        $bytes = $this->mp4(12);
        $upload = $this->upload('uitleg.mp4', $bytes);
        try {
            $processed = app(WallboardMediaVideoProcessor::class)->process($upload);
            self::assertSame('video', $processed->kind);
            self::assertSame('video/mp4', $processed->mimeType);
            self::assertSame(12, $processed->durationSeconds);
            self::assertSame(1280, $processed->width);
            self::assertSame(720, $processed->height);
            self::assertSame(hash('sha256', $bytes), $processed->sha256);

            $asset = app(WallboardMediaAssetService::class)->upload(
                ['file' => $upload, 'display_name' => 'Uitlegvideo'],
                $this->actor(),
                Request::create('/api/admin/wallboard-media/assets', 'POST'),
            );
            self::assertSame('video', $asset->kind);
            self::assertSame('video/mp4', $asset->mime_type);
            self::assertSame(12, $asset->duration_seconds);
            self::assertSame(1280, $asset->width);
            self::assertSame(720, $asset->height);
            self::assertSame(100, $asset->processing_progress);
            self::assertNull($asset->thumbnail_storage_path);
            Storage::disk('local')->assertExists((string) $asset->storage_path);
        } finally {
            @unlink((string) $upload->getRealPath());
            if (isset($processed) && is_file($processed->temporaryPath)) {
                @unlink($processed->temporaryPath);
            }
        }
    }

    public function test_spoofed_mp4_is_rejected(): void
    {
        $upload = $this->upload('spoof.mp4', '<html>not video</html>');
        try {
            app(WallboardMediaVideoProcessor::class)->process($upload);
            self::fail('Een ongeldige MP4 moet worden geweigerd.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('file', $exception->errors());
        } finally {
            @unlink((string) $upload->getRealPath());
        }
    }

    public function test_mp4_missing_required_index_or_media_data_is_rejected(): void
    {
        foreach ([
            ['zonder-index.mp4', $this->mp4(10, includeMovieIndex: false)],
            ['zonder-videodata.mp4', $this->mp4(10, includeMediaData: false)],
        ] as [$name, $bytes]) {
            $upload = $this->upload($name, $bytes);
            try {
                app(WallboardMediaVideoProcessor::class)->process($upload);
                self::fail('Een structureel onvolledige MP4 moet worden geweigerd.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('file', $exception->errors());
            } finally {
                @unlink((string) $upload->getRealPath());
            }
        }
    }

    public function test_mp4_with_late_playback_index_is_queued_for_fast_start_transcoding(): void
    {
        $upload = $this->upload('late-index.mp4', $this->mp4(10, false));
        try {
            $processed = app(WallboardMediaVideoProcessor::class)->process($upload);

            self::assertTrue($processed->requiresVideoTranscode);
        } finally {
            @unlink((string) $upload->getRealPath());
            if (isset($processed) && is_file($processed->temporaryPath)) {
                @unlink($processed->temporaryPath);
            }
        }
    }

    public function test_ftyp_minor_version_is_not_treated_as_a_browser_compatible_brand(): void
    {
        $upload = $this->upload('iso8.mp4', $this->mp4(
            12,
            majorBrand: 'iso8',
            minorVersion: 'isom',
            compatibleBrands: ['iso8'],
        ));

        try {
            $processed = app(WallboardMediaVideoProcessor::class)->process($upload);

            self::assertTrue($processed->requiresVideoTranscode);
        } finally {
            @unlink((string) $upload->getRealPath());
            if (isset($processed) && is_file($processed->temporaryPath)) {
                @unlink($processed->temporaryPath);
            }
        }
    }

    public function test_video_size_limit_is_enforced_before_container_processing(): void
    {
        config()->set('wallboard_media.max_video_upload_kilobytes', 1);
        $upload = $this->upload('te-groot.mp4', $this->mp4(10).str_repeat("\x00", 2048));
        try {
            app(WallboardMediaVideoProcessor::class)->process($upload);
            self::fail('Een MP4 boven de ingestelde limiet moet worden geweigerd.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('file', $exception->errors());
        } finally {
            @unlink((string) $upload->getRealPath());
        }
    }

    public function test_default_video_upload_limit_matches_the_512_mib_server_ceiling(): void
    {
        self::assertSame(512 * 1024, config('wallboard_media.max_video_upload_kilobytes'));
    }

    public function test_oversized_video_is_queued_and_atomically_converted_to_1080p(): void
    {
        Queue::fake();
        $probeCount = 0;
        Process::fake(function (PendingProcess $process) use (&$probeCount) {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === '/usr/bin/ffprobe') {
                $probeCount++;

                return $this->probeResult($process, $probeCount === 1 ? 3840 : 1920, $probeCount === 1 ? 2160 : 1080);
            }
            if (($command[0] ?? null) === '/usr/bin/ffmpeg') {
                $outputPath = end($command);
                self::assertIsString($outputPath);
                self::assertSame(strlen($this->mp4(12)), file_put_contents($outputPath, $this->mp4(12)));

                $progress = [];
                foreach (range(1, 98) as $percentage) {
                    $progress[] = 'out_time_us='.(int) (12_000_000 * ($percentage / 100));
                    $progress[] = 'progress=continue';
                }
                $progress[] = 'progress=end';

                return Process::describe()->output($progress);
            }

            return new FakeProcessResult(exitCode: 1);
        });

        $upload = $this->upload('vier-k.mp4', $this->mp4(12));
        try {
            $asset = app(WallboardMediaAssetService::class)->upload(
                ['file' => $upload, 'display_name' => 'Vier K'],
                $this->actor(),
                Request::create('/api/admin/wallboard-media/assets', 'POST'),
            );
            self::assertSame(WallboardMediaAsset::STATUS_PROCESSING, $asset->status);
            self::assertSame(0, $asset->processing_progress);
            self::assertSame(3840, $asset->width);
            self::assertSame(2160, $asset->height);
            Queue::assertPushed(TranscodeWallboardMediaVideo::class, fn ($job) => $job->assetId === $asset->id);

            $progressUpdateQueries = 0;
            DB::listen(function (QueryExecuted $query) use (&$progressUpdateQueries): void {
                if (str_starts_with(strtolower(ltrim($query->sql)), 'update')
                    && str_contains(strtolower($query->sql), 'processing_progress')) {
                    $progressUpdateQueries++;
                }
            });

            (new TranscodeWallboardMediaVideo((string) $asset->id))->handle(
                app(WallboardMediaVideoTranscodeService::class),
            );
            $asset->refresh();
            self::assertSame(WallboardMediaAsset::STATUS_READY, $asset->status);
            self::assertSame(100, $asset->processing_progress);
            self::assertSame(1920, $asset->width);
            self::assertSame(1080, $asset->height);
            self::assertSame(12, $asset->duration_seconds);
            self::assertSame(2, $asset->version);
            self::assertLessThanOrEqual(22, $progressUpdateQueries);
            Process::assertRan(fn (PendingProcess $process) => is_array($process->command)
                && ($process->command[0] ?? null) === '/usr/bin/ffmpeg'
                && in_array('-progress', $process->command, true)
                && in_array('pipe:1', $process->command, true)
                && in_array('-nostats', $process->command, true)
                && in_array('+faststart', $process->command, true)
                && in_array('yuv420p', $process->command, true)
                && in_array('libx264', $process->command, true)
                && in_array('aac', $process->command, true));
        } finally {
            @unlink((string) $upload->getRealPath());
        }
    }

    public function test_progress_persistence_failure_is_reported_once_without_aborting_transcode(): void
    {
        Exceptions::fake([\RuntimeException::class]);
        $sourceBytes = $this->mp4(12, false);
        $transcodedBytes = $this->mp4(12);
        $asset = $this->storedVideo($this->actor(), $sourceBytes);
        $asset->forceFill([
            'width' => 3840,
            'height' => 2160,
            'duration_seconds' => 12,
            'status' => WallboardMediaAsset::STATUS_PROCESSING,
            'processing_progress' => 0,
        ])->save();

        Process::fake(function (PendingProcess $process) use ($transcodedBytes) {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === '/usr/bin/ffprobe') {
                return $this->probeResult($process, 1920, 1080);
            }
            if (($command[0] ?? null) === '/usr/bin/ffmpeg') {
                $outputPath = end($command);
                self::assertIsString($outputPath);
                self::assertSame(strlen($transcodedBytes), file_put_contents($outputPath, $transcodedBytes));

                return Process::describe()->output([
                    'out_time_us=1200000',
                    'progress=continue',
                    'out_time_us=6000000',
                    'progress=continue',
                    'progress=end',
                ]);
            }

            return new FakeProcessResult(exitCode: 1);
        });
        $progressFailureInjected = false;
        DB::listen(function (QueryExecuted $query) use (&$progressFailureInjected): void {
            if (! $progressFailureInjected
                && str_starts_with(strtolower(ltrim($query->sql)), 'update')
                && str_contains(strtolower($query->sql), 'processing_progress')) {
                $progressFailureInjected = true;

                throw new \RuntimeException('Simulated progress persistence failure.');
            }
        });

        app(WallboardMediaVideoTranscodeService::class)->transcode((string) $asset->id);

        $asset->refresh();
        self::assertTrue($progressFailureInjected);
        self::assertSame(WallboardMediaAsset::STATUS_READY, $asset->status);
        self::assertSame(100, $asset->processing_progress);
        Exceptions::assertReported(
            fn (\RuntimeException $exception): bool => $exception->getMessage() === 'Simulated progress persistence failure.',
        );
        Exceptions::assertReportedCount(1);
    }

    public function test_maintenance_interruption_from_progress_callback_is_not_swallowed(): void
    {
        $sourceBytes = $this->mp4(12, false);
        $transcodedBytes = $this->mp4(12);
        $asset = $this->storedVideo($this->actor(), $sourceBytes);
        $asset->forceFill([
            'duration_seconds' => 12,
            'status' => WallboardMediaAsset::STATUS_PROCESSING,
            'processing_progress' => 0,
        ])->save();
        Process::fake(function (PendingProcess $process) use ($transcodedBytes) {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === '/usr/bin/ffmpeg') {
                $outputPath = end($command);
                self::assertIsString($outputPath);
                self::assertSame(strlen($transcodedBytes), file_put_contents($outputPath, $transcodedBytes));

                return Process::describe()->output([
                    'out_time_us=1200000',
                    'progress=continue',
                ]);
            }

            return $this->probeResult($process, 1920, 1080);
        });
        $interruptChecks = 0;

        try {
            app(WallboardMediaVideoTranscodeService::class)->transcode(
                (string) $asset->id,
                static function () use (&$interruptChecks): bool {
                    $interruptChecks++;

                    return $interruptChecks >= 2;
                },
            );
            self::fail('Een onderhoudsinterruptie tijdens FFmpeg moet de transcode afbreken.');
        } catch (HttpException $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertStringContainsString('onderbroken', $exception->getMessage());
        }

        $asset->refresh();
        self::assertSame(WallboardMediaAsset::STATUS_PROCESSING, $asset->status);
        self::assertSame(0, $asset->processing_progress);
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === '/usr/bin/ffmpeg');
    }

    public function test_duplicate_job_lock_contention_is_a_non_terminal_noop(): void
    {
        $asset = $this->storedVideo($this->actor(), $this->mp4(12, false));
        $asset->forceFill([
            'status' => WallboardMediaAsset::STATUS_PROCESSING,
            'processing_progress' => 35,
        ])->save();
        $originalVersion = (int) $asset->version;
        $lock = Cache::lock('wallboard-media:video-transcode:'.$asset->id, 30);
        self::assertTrue($lock->get());
        Process::fake();

        try {
            (new TranscodeWallboardMediaVideo((string) $asset->id))->handle(
                app(WallboardMediaVideoTranscodeService::class),
            );
        } finally {
            $lock->release();
        }

        $asset->refresh();
        self::assertSame(WallboardMediaAsset::STATUS_PROCESSING, $asset->status);
        self::assertSame(35, $asset->processing_progress);
        self::assertSame($originalVersion, $asset->version);
        self::assertFalse(AuditLog::query()
            ->where('action', 'wallboard_media.assets.video_transcode_failed')
            ->exists());
        Process::assertNothingRan();
    }

    public function test_ffmpeg_failure_logs_only_bounded_sanitized_diagnostics(): void
    {
        Log::spy();
        $sourcePath = $this->upload('diagnostic-source.mp4', $this->mp4(12))->getRealPath();
        self::assertIsString($sourcePath);
        $outputPath = null;
        Process::fake(function (PendingProcess $process) use ($sourcePath, &$outputPath): FakeProcessResult {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) !== '/usr/bin/ffmpeg') {
                return new FakeProcessResult(exitCode: 1);
            }

            $outputPath = end($command);
            self::assertIsString($outputPath);

            return new FakeProcessResult(
                exitCode: 23,
                errorOutput: "Failed to open {$sourcePath} and {$outputPath}; "
                    .'C:\\private\\encoder.conf Authorization: Bearer ffmpeg-log-secret '
                    .str_repeat('x', 4_096),
            );
        });

        try {
            app(WallboardMediaVideoProcessor::class)->transcode($sourcePath);
            self::fail('Een mislukte FFmpeg-uitvoering moet een generieke fout retourneren.');
        } catch (HttpException $exception) {
            self::assertSame('De video kon niet veilig naar 1080p worden verwerkt.', $exception->getMessage());
            self::assertStringNotContainsString('ffmpeg-log-secret', $exception->getMessage());
        } finally {
            @unlink($sourcePath);
        }

        self::assertIsString($outputPath);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($sourcePath, $outputPath): bool {
                $diagnostic = (string) ($context['diagnostic'] ?? '');

                return $message === 'Wallboard video transcoding process failed.'
                    && ($context['exit_code'] ?? null) === 23
                    && strlen($diagnostic) <= 2_048
                    && str_contains($diagnostic, '[MEDIA_PATH]')
                    && str_contains($diagnostic, '[PATH]')
                    && str_contains($diagnostic, '[REDACTED]')
                    && ! str_contains($diagnostic, $sourcePath)
                    && ! str_contains($diagnostic, $outputPath)
                    && ! str_contains($diagnostic, 'ffmpeg-log-secret')
                    && ! str_contains($diagnostic, 'encoder.conf');
            });
    }

    public function test_audit_failure_after_video_replacement_restores_original_file_and_database_state(): void
    {
        $originalBytes = $this->mp4(12, false);
        $transcodedBytes = $this->mp4(12);
        self::assertNotSame(hash('sha256', $originalBytes), hash('sha256', $transcodedBytes));

        $asset = $this->storedVideo($this->actor(), $originalBytes);
        $asset->forceFill([
            'width' => 3840,
            'height' => 2160,
            'duration_seconds' => 12,
            'status' => WallboardMediaAsset::STATUS_PROCESSING,
            'processing_progress' => 0,
        ])->save();
        $originalSha256 = (string) $asset->sha256;
        $originalByteSize = (int) $asset->byte_size;
        $originalVersion = (int) $asset->version;
        $storagePath = (string) $asset->storage_path;

        Process::fake(function (PendingProcess $process) use ($transcodedBytes) {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === '/usr/bin/ffprobe') {
                return $this->probeResult($process, 1920, 1080);
            }
            if (($command[0] ?? null) === '/usr/bin/ffmpeg') {
                $outputPath = end($command);
                self::assertIsString($outputPath);
                self::assertSame(strlen($transcodedBytes), file_put_contents($outputPath, $transcodedBytes));

                return new FakeProcessResult(exitCode: 0);
            }

            return new FakeProcessResult(exitCode: 1);
        });
        AuditLog::creating(function (AuditLog $auditLog) use ($storagePath, $transcodedBytes): void {
            if ($auditLog->action !== 'wallboard_media.assets.video_transcoded') {
                return;
            }

            self::assertSame($transcodedBytes, Storage::disk('local')->get($storagePath));

            throw new \RuntimeException('Simulated required audit failure after video replacement.');
        });

        try {
            app(WallboardMediaVideoTranscodeService::class)->transcode((string) $asset->id);
            self::fail('The required audit failure must roll back the video replacement.');
        } catch (HttpException $exception) {
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            self::assertSame(
                'Simulated required audit failure after video replacement.',
                $exception->getPrevious()?->getMessage(),
            );
        } finally {
            AuditLog::flushEventListeners();
        }

        $asset->refresh();
        self::assertSame(WallboardMediaAsset::STATUS_PROCESSING, $asset->status);
        self::assertSame($originalSha256, $asset->sha256);
        self::assertSame($originalByteSize, $asset->byte_size);
        self::assertSame(3840, $asset->width);
        self::assertSame(2160, $asset->height);
        self::assertSame($originalVersion, $asset->version);
        self::assertSame($originalBytes, Storage::disk('local')->get($storagePath));
        self::assertSame($originalSha256, hash('sha256', Storage::disk('local')->get($storagePath)));
    }

    public function test_h265_video_is_queued_and_converted_to_browser_safe_h264(): void
    {
        Queue::fake();
        $probeCount = 0;
        Process::fake(function (PendingProcess $process) use (&$probeCount): FakeProcessResult {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === '/usr/bin/ffprobe') {
                $probeCount++;

                return $this->probeResult(
                    $process,
                    1920,
                    1080,
                    'aac',
                    $probeCount === 1 ? 'hevc' : 'h264',
                );
            }
            if (($command[0] ?? null) === '/usr/bin/ffmpeg') {
                $outputPath = end($command);
                self::assertIsString($outputPath);
                self::assertSame(strlen($this->mp4(12)), file_put_contents($outputPath, $this->mp4(12)));

                return new FakeProcessResult(exitCode: 0);
            }

            return new FakeProcessResult(exitCode: 1);
        });
        $upload = $this->upload('h265-bron.mp4', $this->mp4(12, false));

        try {
            $asset = app(WallboardMediaAssetService::class)->upload(
                ['file' => $upload, 'display_name' => 'H.265 bron'],
                $this->actor(),
                Request::create('/api/admin/wallboard-media/assets', 'POST'),
            );

            self::assertSame(WallboardMediaAsset::STATUS_PROCESSING, $asset->status);
            self::assertSame(0, $asset->processing_progress);
            self::assertSame(1920, $asset->width);
            self::assertSame(1080, $asset->height);
            Queue::assertPushed(TranscodeWallboardMediaVideo::class, fn ($job) => $job->assetId === $asset->id);

            (new TranscodeWallboardMediaVideo((string) $asset->id))->handle(
                app(WallboardMediaVideoTranscodeService::class),
            );
            $asset->refresh();

            self::assertSame(WallboardMediaAsset::STATUS_READY, $asset->status);
            self::assertSame(100, $asset->processing_progress);
            self::assertSame(2, $asset->version);
            Process::assertRan(fn (PendingProcess $process) => is_array($process->command)
                && ($process->command[0] ?? null) === '/usr/bin/ffmpeg'
                && in_array('libx264', $process->command, true)
                && in_array('aac', $process->command, true)
                && in_array('+faststart', $process->command, true));
        } finally {
            @unlink((string) $upload->getRealPath());
        }
    }

    public function test_transcode_rejects_output_without_a_fast_start_index(): void
    {
        Process::fake(function (PendingProcess $process): FakeProcessResult {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === '/usr/bin/ffmpeg') {
                $outputPath = end($command);
                self::assertIsString($outputPath);
                self::assertSame(strlen($this->mp4(12, false)), file_put_contents($outputPath, $this->mp4(12, false)));

                return new FakeProcessResult(exitCode: 0);
            }

            return $this->probeResult($process, 1920, 1080);
        });
        $upload = $this->upload('bron.mp4', $this->mp4(12));

        try {
            app(WallboardMediaVideoProcessor::class)->transcode((string) $upload->getRealPath());
            self::fail('Niet-streambare transcode-uitvoer mag niet worden geactiveerd.');
        } catch (HttpException $exception) {
            self::assertSame(503, $exception->getStatusCode());
        } finally {
            @unlink((string) $upload->getRealPath());
        }
    }

    public function test_non_aac_audio_is_queued_for_browser_safe_transcoding(): void
    {
        Process::fake(fn (PendingProcess $process) => $this->probeResult($process, 1280, 720, 'ac3'));
        $upload = $this->upload('surround.mp4', $this->mp4(12));

        try {
            $processed = app(WallboardMediaVideoProcessor::class)->process($upload);

            self::assertTrue($processed->requiresVideoTranscode);
        } finally {
            @unlink((string) $upload->getRealPath());
            if (isset($processed) && is_file($processed->temporaryPath)) {
                @unlink($processed->temporaryPath);
            }
        }
    }

    public function test_failed_transcodes_remain_counted_against_media_quota(): void
    {
        $bytes = $this->mp4(8);
        $asset = $this->storedVideo($this->actor(), $bytes);
        $asset->forceFill(['status' => WallboardMediaAsset::STATUS_FAILED])->save();

        $repository = app(WallboardMediaAssetRepository::class);
        self::assertSame(strlen($bytes), $repository->activeByteSize());
        self::assertSame(1, $repository->activeCount());
    }

    public function test_failed_job_clears_processing_progress(): void
    {
        $asset = $this->storedVideo($this->actor(), $this->mp4(8));
        $asset->forceFill([
            'status' => WallboardMediaAsset::STATUS_PROCESSING,
            'processing_progress' => 55,
        ])->save();

        (new TranscodeWallboardMediaVideo((string) $asset->id))->failed(null);

        $asset->refresh();
        self::assertSame(WallboardMediaAsset::STATUS_FAILED, $asset->status);
        self::assertNull($asset->processing_progress);
        self::assertSame(2, $asset->version);
    }

    public function test_interrupted_transcode_is_republished_before_reserved_job_is_deleted(): void
    {
        Queue::fake();
        $actor = $this->actor();
        $bytes = $this->mp4(12);
        $assetId = (string) Str::ulid();
        $path = 'wallboard-media/objects/'.$assetId.'.mp4';
        Storage::disk('local')->put($path, $bytes);
        $asset = WallboardMediaAsset::query()->create([
            'id' => $assetId,
            'display_name' => 'Onderbroken transcode',
            'original_name' => 'onderbroken.mp4',
            'kind' => WallboardMediaAsset::KIND_VIDEO,
            'storage_path' => $path,
            'sha256' => hash('sha256', $bytes),
            'mime_type' => 'video/mp4',
            'byte_size' => strlen($bytes),
            'width' => 3840,
            'height' => 2160,
            'duration_seconds' => 12,
            'status' => WallboardMediaAsset::STATUS_PROCESSING,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $job = (new TranscodeWallboardMediaVideo($assetId))->withFakeQueueInteractions();
        $job->interrupted(15);
        $job->handle(app(WallboardMediaVideoTranscodeService::class));

        $job->assertDeleted();
        Queue::assertPushed(
            TranscodeWallboardMediaVideo::class,
            fn (TranscodeWallboardMediaVideo $replacement): bool => $replacement->assetId === $assetId
                && $replacement->delay === $job->backoff,
        );
        self::assertSame(WallboardMediaAsset::STATUS_PROCESSING, $asset->fresh()?->status);
        Process::assertNothingRan();
    }

    public function test_video_cannot_be_added_to_photo_playlist_or_photo_state(): void
    {
        $actor = $this->actor();
        $video = $this->storedVideo($actor, $this->mp4(8));

        try {
            app(WallboardMediaPlaylistService::class)->create([
                'name' => 'Geen video',
                'asset_ids' => [(string) $video->id],
            ], $actor, Request::create('/api/admin/wallboard-media/playlists', 'POST'));
            self::fail('Een video mag niet in een fotoplaylist worden opgenomen.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('asset_ids', $exception->errors());
        }
    }

    public function test_media_response_supports_single_byte_ranges_and_rejects_invalid_ranges(): void
    {
        $path = 'wallboard-media/objects/'.Str::ulid().'.mp4';
        Storage::disk('local')->put($path, '0123456789');
        $content = new WallboardMediaContent('local', $path, 'video/mp4', 10, '"etag"');
        $request = Request::create('/media', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=2-5']);
        $response = WallboardMediaResponse::make($request, $content, 3600);
        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('bytes 2-5/10', $response->headers->get('Content-Range'));
        self::assertSame('4', $response->headers->get('Content-Length'));
        ob_start();
        $response->sendContent();
        self::assertSame('2345', ob_get_clean());

        $invalid = WallboardMediaResponse::make(
            Request::create('/media', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=12-20']),
            $content,
            3600,
        );
        self::assertSame(416, $invalid->getStatusCode());
        self::assertSame('bytes */10', $invalid->headers->get('Content-Range'));
    }

    public function test_admin_thumbnail_is_verified_and_video_thumbnail_is_not_exposed(): void
    {
        $actor = $this->actor();
        $imageId = (string) Str::ulid();
        $imageBody = base64_decode('UklGRiIAAABXRUJQVlA4ICAAAADQAQCdASoBAAEAL0AcJaQAA3AA/v89WAAAAA==', true);
        self::assertIsString($imageBody);
        $imagePath = 'wallboard-media/objects/'.$imageId.'.webp';
        $thumbnailPath = 'wallboard-media/objects/'.$imageId.'.thumbnail.webp';
        Storage::disk('local')->put($imagePath, $imageBody);
        Storage::disk('local')->put($thumbnailPath, $imageBody);
        $image = WallboardMediaAsset::query()->create([
            'id' => $imageId,
            'display_name' => 'Foto',
            'original_name' => 'foto.webp',
            'kind' => 'image',
            'storage_path' => $imagePath,
            'thumbnail_storage_path' => $thumbnailPath,
            'thumbnail_sha256' => hash('sha256', $imageBody),
            'thumbnail_mime_type' => 'image/webp',
            'thumbnail_byte_size' => strlen($imageBody),
            'sha256' => hash('sha256', $imageBody),
            'mime_type' => 'image/webp',
            'byte_size' => strlen($imageBody),
            'width' => 1,
            'height' => 1,
            'status' => 'ready',
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        self::assertNotNull(app(WallboardMediaDeliveryService::class)->thumbnailForAdmin($image));

        $video = $this->storedVideo($actor, $this->mp4(6));
        self::assertNull(app(WallboardMediaDeliveryService::class)->thumbnailForAdmin($video));
    }

    private function actor(): User
    {
        return User::query()->create([
            'name' => 'Media Beheerder',
            'first_name' => 'Media',
            'last_name' => 'Beheerder',
            'email' => 'media-'.Str::lower((string) Str::ulid()).'@example.test',
            'password' => bcrypt('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function storedVideo(User $actor, string $bytes): WallboardMediaAsset
    {
        $id = (string) Str::ulid();
        $path = 'wallboard-media/objects/'.$id.'.mp4';
        Storage::disk('local')->put($path, $bytes);

        return WallboardMediaAsset::query()->create([
            'id' => $id,
            'display_name' => 'Video',
            'original_name' => 'video.mp4',
            'kind' => 'video',
            'storage_path' => $path,
            'sha256' => hash('sha256', $bytes),
            'mime_type' => 'video/mp4',
            'byte_size' => strlen($bytes),
            'width' => null,
            'height' => null,
            'duration_seconds' => 8,
            'status' => 'ready',
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function upload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wallboard-video-');
        self::assertIsString($path);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param list<string> $compatibleBrands */
    private function mp4(
        int $seconds,
        bool $fastStart = true,
        bool $includeMovieIndex = true,
        bool $includeMediaData = true,
        string $majorBrand = 'isom',
        string $minorVersion = "\x00\x00\x00\x00",
        array $compatibleBrands = ['isom', 'mp42'],
    ): string {
        $box = static fn (string $type, string $payload): string => pack('N', strlen($payload) + 8).$type.$payload;
        $ftyp = $box('ftyp', $majorBrand.$minorVersion.implode('', $compatibleBrands));
        $mvhd = $box('mvhd', "\x00\x00\x00\x00".pack('N4', 0, 0, 1000, $seconds * 1000));
        $moov = $includeMovieIndex ? $box('moov', $mvhd) : '';
        $mdat = $includeMediaData ? $box('mdat', str_repeat("\x00", 32)) : '';

        return $fastStart ? $ftyp.$moov.$mdat : $ftyp.$mdat.$moov;
    }

    private function probeResult(
        PendingProcess $process,
        int $width,
        int $height,
        ?string $audioCodec = 'aac',
        string $videoCodec = 'h264',
    ): FakeProcessResult {
        $command = is_array($process->command) ? $process->command : [];
        if (($command[0] ?? null) !== '/usr/bin/ffprobe') {
            return new FakeProcessResult(exitCode: 1);
        }

        $streams = [[
            'codec_type' => 'video',
            'codec_name' => $videoCodec,
            'pix_fmt' => 'yuv420p',
            'width' => $width,
            'height' => $height,
        ]];
        if ($audioCodec !== null) {
            $streams[] = [
                'codec_type' => 'audio',
                'codec_name' => $audioCodec,
            ];
        }

        return new FakeProcessResult(output: json_encode([
            'streams' => $streams,
            'format' => [
                'duration' => '12.000000',
                'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
