<?php

namespace Tests\Feature;

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
        config()->set('dis.speech.audio_recipe_revision', 'consistent-speaker-loudness-v3');
        $changedRecipe = $pipeline->segmentCacheKey('Dit is een melding.', $model, null, 1.0);

        $this->assertNotSame($current, $changedRecipe);

        config()->set('dis.speech.audio_recipe_revision', 'consistent-speaker-loudness-v2');
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

        $composite = app(SpeechAudioPipeline::class)->composite($assets, 'attendance', null);

        $this->assertSame(2500, $composite->duration_ms);
        $this->assertCount(4, $commands);
        $this->assertDatabaseHas('speech_cache_entries', [
            'category' => 'composite',
            'audio_asset_id' => $composite->id,
        ]);
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
