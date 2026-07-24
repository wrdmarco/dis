<?php

namespace Tests\Feature;

use App\Contracts\SpeechEngineClient;
use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechModelInstallation;
use App\Models\SpeechVoiceProfile;
use App\Services\SpeechAudioPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class SpeechAudioPipelineConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $runtimeRoot = storage_path('framework/testing/speech-consistency-'.str()->ulid());
        config()->set([
            'dis.speech.cache_hmac_key' => str_repeat('pipeline-consistency-key-', 2),
            'dis.speech.staging_root' => $runtimeRoot.DIRECTORY_SEPARATOR.'staging',
            'dis.speech.cache_root' => $runtimeRoot.DIRECTORY_SEPARATOR.'cache',
        ]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($runtimeRoot));
    }

    public function test_segment_cache_key_tracks_the_complete_speaker_and_audio_recipe(): void
    {
        $model = new SpeechModelInstallation([
            'catalog_key' => 'voxcpm2',
            'revision' => 'pinned-model-revision',
            'weights_sha256' => str_repeat('a', 64),
        ]);
        $pipeline = app(SpeechAudioPipeline::class);

        $current = $pipeline->segmentCacheKey('Dit is een melding.', $model, null, 1.0);
        $currentVoiceDesign = config('dis.speech.models.voxcpm2.built_in_voice_design_revision');
        config()->set(
            'dis.speech.models.voxcpm2.built_in_voice_design_revision',
            'voxcpm2-nl-nl-female-neutral-pa-v4',
        );
        $changedVoiceDesign = $pipeline->segmentCacheKey('Dit is een melding.', $model, null, 1.0);
        $this->assertNotSame($current, $changedVoiceDesign);
        config()->set('dis.speech.models.voxcpm2.built_in_voice_design_revision', $currentVoiceDesign);

        config()->set('dis.speech.audio_recipe_revision', 'consistent-speaker-loudness-v4');
        $changedRecipe = $pipeline->segmentCacheKey('Dit is een melding.', $model, null, 1.0);

        $this->assertNotSame($current, $changedRecipe);

        config()->set('dis.speech.audio_recipe_revision', 'consistent-speaker-loudness-v3');
        $firstProfile = new SpeechVoiceProfile([
            'sample_sha256' => str_repeat('b', 64),
            'consent_version' => 1,
        ]);
        $firstProfile->id = '01KXT7Z2P01H86GCGV1ZK3D5QD';
        $secondProfile = new SpeechVoiceProfile([
            'sample_sha256' => str_repeat('b', 64),
            'consent_version' => 1,
        ]);
        $secondProfile->id = '01KXT7Z2P01H86GCGV1ZK3D5QE';

        $firstProfileKey = $pipeline->segmentCacheKey('Dit is een melding.', $model, $firstProfile, 1.0);
        $secondProfileKey = $pipeline->segmentCacheKey('Dit is een melding.', $model, $secondProfile, 1.0);

        $this->assertNotSame($firstProfileKey, $secondProfileKey);
    }

    public function test_segment_encoding_uses_two_pass_loudness_normalization_after_speed_control(): void
    {
        $calls = 0;
        Process::fake(function (PendingProcess $process) use (&$calls) {
            $calls++;
            $command = is_array($process->command) ? $process->command : [];
            $filterIndex = array_search('-af', $command, true);
            $this->assertIsInt($filterIndex);
            $filter = (string) ($command[$filterIndex + 1] ?? '');
            $this->assertStringStartsWith('atempo=1.10,loudnorm=I=-18:TP=-1.5:LRA=7', $filter);

            if (in_array('null', $command, true)) {
                return Process::result(errorOutput: <<<'LOUDNESS'
{
    "input_i" : "-27.10",
    "input_tp" : "-8.20",
    "input_lra" : "2.30",
    "input_thresh" : "-37.10",
    "output_i" : "-18.05",
    "output_tp" : "-1.50",
    "output_lra" : "2.10",
    "output_thresh" : "-28.05",
    "normalization_type" : "linear",
    "target_offset" : "0.05"
}
LOUDNESS);
            }

            $this->assertStringContainsString('measured_I=-27.10', $filter);
            $this->assertStringContainsString('measured_TP=-8.20', $filter);
            $this->assertStringContainsString('measured_LRA=2.30', $filter);
            $this->assertStringContainsString('measured_thresh=-37.10', $filter);
            $this->assertStringContainsString('offset=0.05', $filter);
            $this->assertContains('48000', $command);

            return Process::result();
        });

        $method = new \ReflectionMethod(SpeechAudioPipeline::class, 'encodeM4a');
        $method->invoke(app(SpeechAudioPipeline::class), 'input.wav', 'output.m4a', 1.10);

        $this->assertSame(2, $calls);
    }

    public function test_multi_segment_composite_is_normalized_again_after_pcm_composition(): void
    {
        $assets = [
            $this->cachedAsset(str_repeat('FIRST-SEGMENT-', 20)),
            $this->cachedAsset(str_repeat('SECOND-SEGMENT-', 20)),
        ];
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = is_array($process->command) ? $process->command : [];
            $commands[] = $command;
            if (($command[0] ?? null) === config('dis.speech.ffprobe_binary')) {
                return Process::result(output: json_encode([
                    'streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']],
                    'format' => ['duration' => '2.5', 'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
                ], JSON_THROW_ON_ERROR));
            }
            if (in_array('-filter_complex', $command, true)) {
                $this->assertContains('pcm_s16le', $command);
                File::put((string) end($command), 'RIFF'.pack('V', 132).'WAVE'.str_repeat("\0", 128));

                return Process::result();
            }
            if (in_array('null', $command, true)) {
                return Process::result(errorOutput: <<<'LOUDNESS'
{
    "input_i" : "-18.20",
    "input_tp" : "-1.80",
    "input_lra" : "1.20",
    "input_thresh" : "-28.20",
    "target_offset" : "0.10"
}
LOUDNESS);
            }

            $filterIndex = array_search('-af', $command, true);
            $filter = is_int($filterIndex) ? (string) ($command[$filterIndex + 1] ?? '') : '';
            $this->assertStringContainsString('atempo=1.00,loudnorm=I=-18', $filter);
            File::put((string) end($command), str_repeat('NORMALIZED-COMPOSITE-', 20));

            return Process::result();
        });

        $model = new SpeechModelInstallation([
            'catalog_key' => 'voxcpm2',
            'revision' => 'pinned-model-revision',
            'weights_sha256' => str_repeat('a', 64),
        ]);
        $composite = app(SpeechAudioPipeline::class)->composite(
            $assets,
            'attendance',
            null,
            ['Eerste regel.', 'Tweede regel.'],
            $model,
            1.0,
        );

        $this->assertSame(2500, $composite->duration_ms);
        $this->assertCount(4, $commands);
        $this->assertDatabaseHas('speech_cache_entries', [
            'category' => 'composite',
            'audio_asset_id' => $composite->id,
        ]);
        $entry = SpeechCacheEntry::query()->where('audio_asset_id', $composite->id)->sole();
        $this->assertSame("Eerste regel.\nTweede regel.", $entry->display_text);
        $this->assertSame('voxcpm2', $entry->model_catalog_key);
        $this->assertSame('pinned-model-revision', $entry->model_revision);
        $this->assertNull($entry->synthesis_duration_ms);
    }

    public function test_successful_segment_synthesis_records_and_refreshes_only_the_engine_duration(): void
    {
        $engine = new DurationTrackingSpeechEngineFake;
        app()->instance(SpeechEngineClient::class, $engine);
        Process::fake(function (PendingProcess $process) {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === config('dis.speech.ffmpeg_binary')) {
                if (in_array('null', $command, true)) {
                    return Process::result(errorOutput: json_encode([
                        'input_i' => '-24.00',
                        'input_tp' => '-4.00',
                        'input_lra' => '2.00',
                        'input_thresh' => '-34.00',
                        'target_offset' => '0.00',
                    ], JSON_THROW_ON_ERROR));
                }
                $output = end($command);
                $this->assertIsString($output);
                usleep(120_000);
                File::put($output, str_repeat('SYNTHESIS-DURATION-M4A-', 20));

                return Process::result();
            }

            return Process::result(output: json_encode([
                'streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']],
                'format' => [
                    'duration' => '1.25',
                    'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2',
                ],
            ], JSON_THROW_ON_ERROR));
        });
        $model = new SpeechModelInstallation([
            'catalog_key' => 'voxcpm2',
            'revision' => 'pinned-model-revision',
            'weights_sha256' => str_repeat('a', 64),
        ]);
        $pipeline = app(SpeechAudioPipeline::class);

        $engine->delayMicroseconds = 20_000;
        $pipelineStartedAt = hrtime(true);
        $asset = $pipeline->segment('Dit fragment meet de synthesetijd.', $model, null, 1.0);
        $pipelineDurationMs = (int) ceil((hrtime(true) - $pipelineStartedAt) / 1_000_000);
        $entry = SpeechCacheEntry::query()->where('audio_asset_id', $asset->id)->sole();
        $firstDuration = $entry->synthesis_duration_ms;
        $this->assertIsInt($firstDuration);
        $this->assertGreaterThanOrEqual(10, $firstDuration);
        $this->assertGreaterThanOrEqual($firstDuration + 80, $pipelineDurationMs);
        $entry->forceFill(['synthesis_duration_ms' => 999_999])->save();

        $engine->delayMicroseconds = 80_000;
        $pipeline->segment(
            'Dit fragment meet de synthesetijd.',
            $model,
            null,
            1.0,
            forceRegeneration: true,
        );
        $entry->refresh();
        $secondDuration = $entry->synthesis_duration_ms;
        $this->assertIsInt($secondDuration);
        $this->assertGreaterThanOrEqual(60, $secondDuration);
        $this->assertNotSame(999_999, $secondDuration);

        $engine->delayMicroseconds = 5_000;
        $engine->failSynthesis = true;
        try {
            $pipeline->segment(
                'Dit fragment meet de synthesetijd.',
                $model,
                null,
                1.0,
                forceRegeneration: true,
            );
            $this->fail('Mislukte synthese mocht geen cache-item publiceren.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('engine_failed', $exception->getMessage());
        }

        $entry->refresh();
        $this->assertSame($secondDuration, $entry->synthesis_duration_ms);
        $this->assertSame('ready', $entry->status);
        $this->assertSame(3, $engine->synthesisCalls);
    }

    private function cachedAsset(string $bytes): SpeechAudioAsset
    {
        $sha256 = hash('sha256', $bytes);
        $relative = 'objects/'.substr($sha256, 0, 2).'/'.$sha256.'.m4a';
        $path = rtrim((string) config('dis.speech.cache_root'), '/\\')
            .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $bytes);

        $asset = SpeechAudioAsset::query()->create([
            'content_sha256' => $sha256,
            'storage_path' => $relative,
            'mime_type' => 'audio/mp4',
            'byte_size' => strlen($bytes),
            'duration_ms' => 1000,
        ]);
        SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'test-'.$sha256),
            'category' => 'segment',
            'semantic_hmac' => hash('sha256', 'semantic-'.$sha256),
            'status' => 'ready',
            'audio_asset_id' => $asset->id,
            'last_used_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        return $asset;
    }
}

final class DurationTrackingSpeechEngineFake implements SpeechEngineClient
{
    public int $delayMicroseconds = 0;

    public int $synthesisCalls = 0;

    public bool $failSynthesis = false;

    public function health(): array
    {
        return ['status' => 'ok', 'ready' => true];
    }

    public function install(string $modelId, array $model): array
    {
        return ['status' => 'installed'];
    }

    public function cancelInstall(string $modelId): array
    {
        return ['status' => 'cancelled'];
    }

    public function status(string $modelId): array
    {
        return ['status' => 'installed'];
    }

    public function synthesize(string $modelId, string $jobBasename, string $outputBasename): array
    {
        $this->synthesisCalls++;
        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }
        if ($this->failSynthesis) {
            throw new \RuntimeException('engine_failed');
        }
        $root = (string) config('dis.speech.staging_root');
        File::ensureDirectoryExists($root);
        File::put(
            $root.DIRECTORY_SEPARATOR.$outputBasename,
            'RIFF'.pack('V', 132).'WAVE'.str_repeat("\0", 128),
        );

        return ['duration_ms' => 1000];
    }
}
