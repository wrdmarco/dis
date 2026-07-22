<?php

namespace App\Services;

use App\Contracts\SpeechEngineClient;
use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechModelInstallation;
use App\Models\SpeechVoiceProfile;
use App\Repositories\SpeechAudioCacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

final class SpeechAudioPipeline
{
    public function __construct(
        private readonly SpeechEngineClient $engine,
        private readonly SpeechVoiceProfileService $voices,
        private readonly SpeechCacheKeyService $keys,
        private readonly SpeechAudioCacheRepository $cache,
        private readonly SpeechCachePruner $pruner,
        private readonly SpeechExclusiveFileWriter $files,
    ) {}

    public function segment(
        string $text,
        SpeechModelInstallation $model,
        ?SpeechVoiceProfile $voice,
        float $speed,
        string $category = 'segment',
    ): SpeechAudioAsset {
        $this->assertVoiceMode($model, $voice);
        $speed = $this->speed($speed);
        $cacheKey = $this->segmentCacheKey($text, $model, $voice, $speed, $category);

        $lockSeconds = max(900, (int) config('dis.speech.synthesis_timeout_seconds', 14_400) + 600);

        return Cache::lock('speech-audio:'.$cacheKey, $lockSeconds)->block(5, function () use (
            $cacheKey, $category, $text, $model, $voice, $speed,
        ): SpeechAudioAsset {
            $ready = $this->verifiedReady($cacheKey);
            if ($ready !== null) {
                $this->cache->recordHit($ready);

                return $ready->audioAsset;
            }
            $this->cache->recordMiss();
            $root = $this->stagingRoot();
            $ulid = (string) Str::ulid();
            $jobBasename = $ulid.'.job.json';
            $outputBasename = $ulid.'.wav';
            $referenceBasename = $voice === null ? null : (string) Str::ulid().'.reference';
            $jobPath = $root.DIRECTORY_SEPARATOR.$jobBasename;
            $wavPath = $root.DIRECTORY_SEPARATOR.$outputBasename;
            $m4aPath = $root.DIRECTORY_SEPARATOR.$ulid.'.m4a';
            $cleanupReference = null;
            try {
                if ($voice !== null && $referenceBasename !== null) {
                    $reference = $this->voices->decryptedReference($voice, $referenceBasename);
                    $cleanupReference = $reference['cleanup'];
                }
                $job = [
                    'text' => trim($text),
                    'locale' => 'nl-NL',
                    'model_id' => (string) $model->catalog_key,
                    'voice_reference_basename' => $referenceBasename,
                    'voice_transcript' => $voice?->transcript,
                ];
                $encoded = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (strlen($encoded) > 16_384) {
                    throw new \RuntimeException('Speech synthesis job could not be staged.');
                }
                $this->files->write($jobPath, $encoded);
                $this->engine->synthesize((string) $model->catalog_key, $jobBasename, $outputBasename);
                $this->assertWave($wavPath);
                $this->encodeM4a($wavPath, $m4aPath, $speed);
                $metadata = $this->inspectM4a($m4aPath);

                return $this->publish($cacheKey, $category, $voice?->id, $text, $m4aPath, $metadata);
            } finally {
                @unlink($jobPath);
                @unlink($wavPath);
                @unlink($m4aPath);
                if (is_callable($cleanupReference)) {
                    $cleanupReference();
                }
            }
        });
    }

    public function segmentCacheKey(
        string $text,
        SpeechModelInstallation $model,
        ?SpeechVoiceProfile $voice,
        float $speed,
        string $category = 'segment',
    ): string {
        $this->assertVoiceMode($model, $voice);
        $speed = $this->speed($speed);

        return $this->keys->key($category, [
            'text' => trim($text),
            'locale' => 'nl-NL',
            'model' => $model->catalog_key,
            'revision' => $model->revision,
            'weights_sha256' => $model->weights_sha256,
            'voice_sha256' => $voice?->sample_sha256,
            'voice_consent_version' => $voice?->consent_version,
            'voice_mode' => $voice === null ? 'built_in_design' : 'profile_clone',
            'voice_design_revision' => $voice === null
                ? config('dis.speech.models.'.$model->catalog_key.'.built_in_voice_design_revision')
                : null,
            'speed' => number_format($speed, 2, '.', ''),
            'codec' => 'aac-lc-m4a-v1',
            'engine_protocol' => (int) config('dis.speech.protocol_version', 1),
        ]);
    }

    /** @param list<SpeechAudioAsset> $segments */
    public function composite(array $segments, string $phase, ?SpeechVoiceProfile $voice): SpeechAudioAsset
    {
        if ($segments === [] || count($segments) > 8) {
            throw new \RuntimeException('Speech composite requires 1 to 8 semantic line segments.');
        }
        $cacheKey = $this->keys->key('composite', [
            'phase' => $phase,
            'segments' => array_map(static fn (SpeechAudioAsset $asset): string => $asset->content_sha256, $segments),
            'codec' => 'aac-lc-m4a-v1',
        ]);

        return Cache::lock('speech-audio:'.$cacheKey, 300)->block(5, function () use ($cacheKey, $segments, $voice): SpeechAudioAsset {
            $ready = $this->verifiedReady($cacheKey);
            if ($ready !== null) {
                $this->cache->recordHit($ready);

                return $ready->audioAsset;
            }
            $this->cache->recordMiss();
            if (count($segments) === 1) {
                $asset = $segments[0];
                $entry = $this->cache->publish(
                    $cacheKey,
                    'composite',
                    $voice?->id,
                    $this->keys->key('composite-semantic', ['segments' => [$asset->content_sha256]]),
                    (string) $asset->content_sha256,
                    (string) $asset->storage_path,
                    (int) $asset->byte_size,
                    (int) $asset->duration_ms,
                    now()->addDays((int) config('dis.speech.composite_retention_days', 7)),
                );

                return $entry->audioAsset;
            }

            $root = $this->stagingRoot();
            $output = $root.DIRECTORY_SEPARATOR.(string) Str::ulid().'.m4a';
            $command = [(string) config('dis.speech.ffmpeg_binary', '/usr/bin/ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error'];
            foreach ($segments as $segment) {
                $command[] = '-i';
                $command[] = $this->verifiedAssetPath($segment);
            }
            $inputs = implode('', array_map(static fn (int $index): string => "[$index:a:0]", array_keys($segments)));
            array_push(
                $command,
                '-filter_complex', $inputs.'concat=n='.count($segments).':v=0:a=1[out]',
                '-map', '[out]', '-c:a', 'aac', '-profile:a', 'aac_low', '-b:a', '128k',
                '-movflags', '+faststart', '-f', 'mp4', '-n', $output,
            );
            try {
                $result = Process::timeout(120)->run($command);
                if (! $result->successful()) {
                    throw new \RuntimeException('Speech composite could not be generated.');
                }
                $metadata = $this->inspectM4a($output);

                return $this->publish(
                    $cacheKey,
                    'composite',
                    $voice?->id,
                    implode('|', array_map(static fn (SpeechAudioAsset $asset): string => (string) $asset->content_sha256, $segments)),
                    $output,
                    $metadata,
                );
            } finally {
                @unlink($output);
            }
        });
    }

    /** @return array{byte_size:int,duration_ms:int,sha256:string} */
    private function inspectM4a(string $path): array
    {
        $bytes = @filesize($path);
        if (! is_int($bytes) || $bytes < 128 || $bytes > 67_108_864) {
            throw new \RuntimeException('Generated speech audio has an invalid size.');
        }
        $result = Process::timeout(15)->run([
            (string) config('dis.speech.ffprobe_binary', '/usr/bin/ffprobe'), '-v', 'error', '-of', 'json',
            '-show_entries', 'stream=codec_type,codec_name:format=duration,format_name', $path,
        ]);
        $decoded = $result->successful() ? json_decode($result->output(), true) : null;
        $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
        $audio = array_values(array_filter($streams, static fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'audio'));
        $video = array_filter($streams, static fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'video');
        $format = strtolower((string) ($decoded['format']['format_name'] ?? ''));
        $duration = filter_var($decoded['format']['duration'] ?? null, FILTER_VALIDATE_FLOAT);
        if (count($audio) !== 1 || ($audio[0]['codec_name'] ?? null) !== 'aac' || $video !== []
            || ! str_contains($format, 'mp4') || ! is_float($duration) || $duration <= 0 || $duration > 300) {
            throw new \RuntimeException('Generated speech audio failed AAC/M4A validation.');
        }
        $sha256 = @hash_file('sha256', $path);
        if (! is_string($sha256)) {
            throw new \RuntimeException('Generated speech audio could not be hashed.');
        }

        return ['byte_size' => $bytes, 'duration_ms' => (int) round($duration * 1000), 'sha256' => $sha256];
    }

    private function assertWave(string $path): void
    {
        $head = is_file($path) ? @file_get_contents($path, false, null, 0, 12) : false;
        if (! is_string($head) || strlen($head) !== 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
            throw new \RuntimeException('Speech engine returned invalid WAV audio.');
        }
    }

    private function encodeM4a(string $input, string $output, float $speed): void
    {
        $result = Process::timeout(120)->run([
            (string) config('dis.speech.ffmpeg_binary', '/usr/bin/ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-i', $input, '-vn', '-af', 'atempo='.number_format($speed, 2, '.', ''),
            '-c:a', 'aac', '-profile:a', 'aac_low', '-b:a', '128k', '-movflags', '+faststart', '-f', 'mp4', '-n', $output,
        ]);
        if (! $result->successful()) {
            throw new \RuntimeException('Speech WAV could not be encoded as AAC/M4A.');
        }
    }

    /** @param array{byte_size:int,duration_ms:int,sha256:string} $metadata */
    private function publish(
        string $cacheKey,
        string $category,
        ?string $voiceProfileId,
        string $semantic,
        string $source,
        array $metadata,
    ): SpeechAudioAsset {
        return Cache::lock('speech-cache-quota-publish', 300)->block(30, fn (): SpeechAudioAsset => $this->publishLocked(
            $cacheKey,
            $category,
            $voiceProfileId,
            $semantic,
            $source,
            $metadata,
        ));
    }

    /** @param array{byte_size:int,duration_ms:int,sha256:string} $metadata */
    private function publishLocked(
        string $cacheKey,
        string $category,
        ?string $voiceProfileId,
        string $semantic,
        string $source,
        array $metadata,
    ): SpeechAudioAsset {
        $relative = 'objects/'.substr($metadata['sha256'], 0, 2).'/'.$metadata['sha256'].'.m4a';
        $destination = $this->writableCacheRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = dirname($destination);
        if ((! is_dir($directory) && ! @mkdir($directory, 0770, true)) || is_link($directory)) {
            throw new \RuntimeException('Speech cache directory is unavailable.');
        }
        if (! SpeechAudioAsset::query()->where('content_sha256', $metadata['sha256'])->exists()) {
            $this->pruner->ensureCapacity($metadata['byte_size']);
        }
        if (is_file($destination)) {
            $existing = @hash_file('sha256', $destination);
            if (! is_string($existing) || ! hash_equals($metadata['sha256'], $existing)) {
                throw new \RuntimeException('Speech cache content-address collision detected.');
            }
        } else {
            $temporary = $destination.'.'.(string) Str::ulid().'.part';
            try {
                $this->files->copy($source, $temporary);
            } catch (\Throwable $exception) {
                @unlink($temporary);
                throw new \RuntimeException('Speech cache asset could not be atomically published.', previous: $exception);
            }
            if (! @rename($temporary, $destination)) {
                @unlink($temporary);
                throw new \RuntimeException('Speech cache asset could not be atomically activated.');
            }
        }

        $days = $category === 'composite'
            ? (int) config('dis.speech.composite_retention_days', 7)
            : (int) config('dis.speech.segment_retention_days', 30);
        $entry = $this->cache->publish(
            $cacheKey, $category, $voiceProfileId, $this->keys->semantic($semantic), $metadata['sha256'], $relative,
            $metadata['byte_size'], $metadata['duration_ms'], now()->addDays($days),
        );

        return $entry->audioAsset;
    }

    private function verifiedReady(string $cacheKey): ?SpeechCacheEntry
    {
        $entry = $this->cache->ready($cacheKey);
        if ($entry === null || ! $entry->audioAsset instanceof SpeechAudioAsset) {
            return null;
        }
        try {
            $this->verifiedAssetPath($entry->audioAsset);
        } catch (\Throwable) {
            return null;
        }

        return $entry;
    }

    public function verifiedAssetPath(SpeechAudioAsset $asset): string
    {
        $relative = (string) $asset->storage_path;
        if (preg_match('#^objects/[a-f0-9]{2}/[a-f0-9]{64}\.m4a$#D', $relative) !== 1) {
            throw new \RuntimeException('Invalid speech cache path.');
        }
        $path = $this->readableCacheRoot().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (! is_file($path) || is_link($path) || @filesize($path) !== (int) $asset->byte_size) {
            throw new \RuntimeException('Speech cache asset is missing.');
        }
        $sha256 = @hash_file('sha256', $path);
        if (! is_string($sha256) || ! hash_equals((string) $asset->content_sha256, $sha256)) {
            throw new \RuntimeException('Speech cache asset integrity check failed.');
        }

        return $path;
    }

    private function stagingRoot(): string
    {
        return $this->writableRoot((string) config('dis.speech.staging_root', '/opt/dis-data/tts/staging'));
    }

    private function writableCacheRoot(): string
    {
        return $this->writableRoot((string) config('dis.speech.cache_root', '/opt/dis-data/tts/cache'));
    }

    private function readableCacheRoot(): string
    {
        $root = (string) config('dis.speech.cache_root', '/opt/dis-data/tts/cache');
        if (! $this->isAbsoluteRoot($root) || is_link($root) || ! is_dir($root) || ! is_readable($root)) {
            throw new \RuntimeException('Speech storage root is unavailable.');
        }

        return rtrim($root, '/\\');
    }

    private function writableRoot(string $root): string
    {
        if (! $this->isAbsoluteRoot($root) || is_link($root)
            || ((! is_dir($root) && ! @mkdir($root, 0770, true)) || ! is_writable($root))) {
            throw new \RuntimeException('Speech storage root is unavailable.');
        }

        return rtrim($root, '/\\');
    }

    private function isAbsoluteRoot(string $root): bool
    {
        return str_starts_with($root, '/')
            || (! app()->environment('production') && preg_match('/^[A-Za-z]:[\\\\\/]/D', $root) === 1);
    }

    private function speed(float $speed): float
    {
        if ($speed < 0.85 || $speed > 1.15) {
            throw new \InvalidArgumentException('Speech speed must be between 0.85 and 1.15.');
        }

        return round($speed, 2);
    }

    private function assertVoiceMode(SpeechModelInstallation $model, ?SpeechVoiceProfile $voice): void
    {
        if ($voice !== null) {
            if (config('dis.speech.models.'.$model->catalog_key.'.capabilities.voice_clone') !== true) {
                throw new \RuntimeException('speech_voice_profile_unsupported');
            }

            return;
        }
        $revision = trim((string) config(
            'dis.speech.models.'.$model->catalog_key.'.built_in_voice_design_revision',
            '',
        ));
        if ($revision === ''
            || config('dis.speech.models.'.$model->catalog_key.'.capabilities.voice_design') !== true) {
            throw new \RuntimeException('speech_voice_profile_required');
        }
    }
}
