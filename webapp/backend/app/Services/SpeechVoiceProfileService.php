<?php

namespace App\Services;

use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechManifestSegment;
use App\Models\SpeechPreparedPhrase;
use App\Models\SpeechPreview;
use App\Models\SpeechVoiceProfile;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SpeechVoiceProfileService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly SpeechExclusiveFileWriter $files,
    ) {}

    public function create(string $name, string $locale, string $transcript, UploadedFile $audio, User $actor): SpeechVoiceProfile
    {
        if ($locale !== 'nl-NL') {
            throw ValidationException::withMessages(['locale' => ['Alleen de vaste Nederlandse locale nl-NL wordt ondersteund.']]);
        }
        $sourcePath = $audio->getRealPath();
        if (! is_string($sourcePath) || ! is_file($sourcePath) || is_link($sourcePath)) {
            throw ValidationException::withMessages(['audio' => ['Het stemfragment kon niet veilig worden gelezen.']]);
        }
        $sourceBytes = @filesize($sourcePath);
        if (! is_int($sourceBytes) || $sourceBytes < 1024 || $sourceBytes > 33_554_432) {
            throw ValidationException::withMessages(['audio' => ['Het stemfragment is ongeldig of groter dan 32 MiB.']]);
        }
        $name = trim($name);
        $transcript = trim($transcript);
        if ($name === '' || mb_strlen($name) > 120 || $transcript === '' || mb_strlen($transcript) > 2000) {
            throw ValidationException::withMessages(['transcript' => ['Naam en transcript zijn verplicht en moeten binnen de lengtegrens blijven.']]);
        }

        $id = (string) Str::ulid();
        $canonical = $this->canonicalWave($sourcePath, $id);
        $bytes = $canonical['bytes'];
        $durationMs = $canonical['duration_ms'];
        $storagePath = 'speech/voices/'.$id.'.enc';
        $ciphertext = Crypt::encryptString(base64_encode($bytes));
        $disk = Storage::disk('local');
        $disk->makeDirectory('speech/voices');
        try {
            $this->files->write($disk->path($storagePath), $ciphertext, 0640);
            $profile = SpeechVoiceProfile::query()->create([
                'id' => $id,
                'name' => $name,
                'locale' => 'nl-NL',
                'transcript' => $transcript,
                'consent_statement' => 'De beheerder bevestigt aantoonbare toestemming van de spreker voor gebruik binnen D.I.S.',
                'consent_recorded_at' => now(),
                'consent_version' => 1,
                'sample_storage_path' => $storagePath,
                'sample_sha256' => hash('sha256', $bytes),
                'sample_byte_size' => strlen($bytes),
                'reference_duration_ms' => $durationMs,
                'status' => 'ready',
                'created_by' => $actor->id,
            ]);
            $this->auditService->record('speech.voice_profile_created', $profile, $actor, [
                'locale' => 'nl-NL',
                'reference_duration_ms' => $durationMs,
                'sample_sha256_prefix' => substr((string) $profile->sample_sha256, 0, 12),
                'consent_version' => 1,
            ]);

            return $profile;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storagePath);
            throw $exception;
        }
    }

    public function delete(SpeechVoiceProfile $profile, User $actor): void
    {
        $path = (string) $profile->sample_storage_path;
        $derivedAssetIds = DB::transaction(function () use ($profile, $actor): array {
            $locked = SpeechVoiceProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $manifestIds = SpeechManifest::query()->where('voice_profile_id', $locked->id)->pluck('id');
            $buildIds = SpeechManifestBuild::query()->where('voice_profile_id', $locked->id)->pluck('id');
            $previewIds = SpeechPreview::query()
                ->where(fn ($query) => $query
                    ->whereIn('speech_manifest_id', $manifestIds)
                    ->orWhereIn('speech_manifest_build_id', $buildIds))
                ->pluck('id');
            $derivedAssetIds = collect()
                ->merge(SpeechCacheEntry::query()->where('voice_profile_id', $locked->id)->pluck('audio_asset_id'))
                ->merge(SpeechManifest::query()->whereIn('id', $manifestIds)->pluck('audio_asset_id'))
                ->merge(SpeechManifestSegment::query()->whereIn('speech_manifest_id', $manifestIds)->pluck('audio_asset_id'))
                ->merge(SpeechPreview::query()->whereIn('id', $previewIds)->pluck('audio_asset_id'))
                ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
            $voiceCacheEntryIds = SpeechCacheEntry::query()
                ->where('voice_profile_id', $locked->id)
                ->pluck('id');
            SpeechPreparedPhrase::query()
                ->whereIn('cache_entry_id', $voiceCacheEntryIds)
                ->update([
                    'status' => 'failed',
                    'progress_percent' => 0,
                    'error_code' => 'speech_voice_consent_revoked',
                    'cache_entry_id' => null,
                    'prepared_at' => null,
                    'updated_at' => now(),
                ]);
            $locked->forceFill(['status' => 'revoked', 'consent_version' => (int) $locked->consent_version + 1])->save();
            SpeechManifestBuild::query()->whereIn('id', $buildIds)->whereIn('status', ['queued', 'processing', 'ready'])->update([
                'status' => 'failed',
                'error_code' => 'speech_voice_consent_revoked',
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
            SpeechPreview::query()->whereIn('id', $previewIds)->update([
                'status' => 'failed',
                'error_code' => 'speech_voice_consent_revoked',
                'audio_asset_id' => null,
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
            SpeechCacheEntry::query()->where('voice_profile_id', $locked->id)->delete();
            if (SystemSetting::string('speech.voice_profile_id') === (string) $locked->id) {
                SystemSetting::query()->updateOrCreate(
                    ['key' => 'speech.voice_profile_id'],
                    ['value' => null, 'is_sensitive' => false, 'updated_by' => $actor->id],
                );
                SystemSetting::query()->updateOrCreate(
                    ['key' => 'speech.enabled'],
                    ['value' => false, 'is_sensitive' => false, 'updated_by' => $actor->id],
                );
            }
            $this->auditService->record('speech.voice_profile_revoked', $locked, $actor, [
                'consent_version' => (int) $locked->consent_version,
            ]);
            $locked->delete();

            return $derivedAssetIds;
        });
        Storage::disk('local')->delete($path);
        $this->removeUnreferencedDerivedBytes($derivedAssetIds, (string) $profile->id);
    }

    /** @param list<string> $assetIds */
    private function removeUnreferencedDerivedBytes(array $assetIds, string $revokedVoiceId): void
    {
        Cache::lock('speech-cache-quota-publish', 300)->block(30, function () use ($assetIds, $revokedVoiceId): void {
            foreach ($assetIds as $assetId) {
                $usedByCache = SpeechCacheEntry::query()->where('audio_asset_id', $assetId)->exists();
                $usedByManifest = SpeechManifest::query()
                    ->where('audio_asset_id', $assetId)
                    ->where(fn ($query) => $query->whereNull('voice_profile_id')->orWhere('voice_profile_id', '!=', $revokedVoiceId))
                    ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->exists();
                $usedBySegment = SpeechManifestSegment::query()
                    ->where('audio_asset_id', $assetId)
                    ->whereHas('manifest', fn ($query) => $query
                        ->where(fn ($manifest) => $manifest
                            ->whereNull('voice_profile_id')
                            ->orWhere('voice_profile_id', '!=', $revokedVoiceId))
                        ->where(fn ($manifest) => $manifest
                            ->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now())))
                    ->exists();
                $usedByPreview = SpeechPreview::query()
                    ->where('audio_asset_id', $assetId)
                    ->where('status', 'ready')
                    ->where('expires_at', '>', now())
                    ->exists();
                if ($usedByCache || $usedByManifest || $usedBySegment || $usedByPreview) {
                    continue;
                }

                $asset = SpeechAudioAsset::query()->find($assetId);
                $relative = (string) ($asset?->storage_path ?? '');
                if (preg_match('#^objects/[a-f0-9]{2}/[a-f0-9]{64}\.m4a$#D', $relative) !== 1) {
                    continue;
                }
                $root = rtrim((string) config('dis.speech.cache_root', '/opt/dis-data/tts/cache'), '/\\');
                if (! str_starts_with($root, '/')
                    && (app()->environment('production') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $root) !== 1)) {
                    continue;
                }
                $file = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (is_file($file) && ! is_link($file)) {
                    @unlink($file);
                }
            }
        });
    }

    /** @return array{path:string,cleanup:callable():void} */
    public function decryptedReference(SpeechVoiceProfile $profile, string $basename): array
    {
        if ($profile->status !== 'ready' || $profile->trashed() || $profile->locale !== 'nl-NL') {
            throw new \RuntimeException('Voice profile is not active.');
        }
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}\.reference$/D', $basename) !== 1) {
            throw new \RuntimeException('Invalid voice staging basename.');
        }
        $ciphertext = Storage::disk('local')->get((string) $profile->sample_storage_path);
        $decoded = base64_decode(Crypt::decryptString($ciphertext), true);
        if (! is_string($decoded) || ! hash_equals((string) $profile->sample_sha256, hash('sha256', $decoded))) {
            throw new \RuntimeException('Encrypted voice profile integrity check failed.');
        }
        $root = $this->stagingRoot();
        $path = $root.DIRECTORY_SEPARATOR.$basename;
        $this->files->write($path, $decoded);

        return ['path' => $path, 'cleanup' => static fn () => @unlink($path)];
    }

    /** @return array{bytes:string,duration_ms:int} */
    private function canonicalWave(string $sourcePath, string $id): array
    {
        $root = $this->stagingRoot();
        $directory = $root.DIRECTORY_SEPARATOR.'.voice-'.$id;
        if (! @mkdir($directory, 0700) || ! @chmod($directory, 0700)) {
            @rmdir($directory);
            throw new \RuntimeException('Private voice conversion staging could not be created.');
        }
        $output = $directory.DIRECTORY_SEPARATOR.$id.'.wav';
        try {
            $result = Process::timeout(60)->run([
                (string) config('dis.speech.ffmpeg_binary', '/usr/bin/ffmpeg'),
                '-nostdin', '-v', 'error', '-n', '-i', $sourcePath,
                '-map', '0:a:0', '-vn', '-ac', '1', '-ar', '24000',
                '-c:a', 'pcm_s16le', '-f', 'wav', $output,
            ]);
            if (! $result->successful() || ! is_file($output) || is_link($output) || ! @chmod($output, 0600)) {
                throw ValidationException::withMessages([
                    'audio' => ['Het stemfragment kon niet veilig naar PCM-audio worden omgezet.'],
                ]);
            }
            $durationMs = $this->canonicalDurationMs($output);
            if ($durationMs < 3000 || $durationMs > 30_000) {
                throw ValidationException::withMessages(['audio' => ['Gebruik een stemfragment van 3 tot en met 30 seconden.']]);
            }
            $byteSize = @filesize($output);
            if (! is_int($byteSize) || $byteSize < 131_072 || $byteSize > 2_097_152) {
                throw ValidationException::withMessages(['audio' => ['De genormaliseerde stemopname heeft een ongeldige grootte.']]);
            }
            $bytes = @file_get_contents($output);
            if (! is_string($bytes) || strlen($bytes) !== $byteSize) {
                throw ValidationException::withMessages(['audio' => ['De genormaliseerde stemopname kon niet volledig worden gelezen.']]);
            }

            return ['bytes' => $bytes, 'duration_ms' => $durationMs];
        } finally {
            if (is_file($output) && ! is_link($output)) {
                @unlink($output);
            }
            @rmdir($directory);
        }
    }

    private function canonicalDurationMs(string $path): int
    {
        $result = Process::timeout(15)->run([
            (string) config('dis.speech.ffprobe_binary', '/usr/bin/ffprobe'),
            '-v', 'error', '-of', 'json', '-show_entries',
            'stream=codec_type,codec_name,sample_rate,channels,sample_fmt:format=duration,format_name', $path,
        ]);
        if (! $result->successful()) {
            throw ValidationException::withMessages(['audio' => ['Het stemfragment kon niet veilig worden geverifieerd.']]);
        }
        $decoded = json_decode($result->output(), true);
        $streams = is_array($decoded['streams'] ?? null) ? $decoded['streams'] : [];
        $audioStreams = array_filter($streams, static fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'audio');
        $videoStreams = array_filter($streams, static fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'video');
        $audio = array_values($audioStreams)[0] ?? null;
        $duration = filter_var($decoded['format']['duration'] ?? null, FILTER_VALIDATE_FLOAT);
        $format = (string) ($decoded['format']['format_name'] ?? '');
        if (count($audioStreams) !== 1 || $videoStreams !== [] || ! is_array($audio)
            || ($audio['codec_name'] ?? null) !== 'pcm_s16le'
            || (string) ($audio['sample_rate'] ?? '') !== '24000'
            || (int) ($audio['channels'] ?? 0) !== 1
            || ($audio['sample_fmt'] ?? null) !== 's16'
            || ! str_contains($format, 'wav')
            || ! is_float($duration) || ! is_finite($duration)) {
            throw ValidationException::withMessages(['audio' => ['Het bestand moet precies één geldig audiospoor bevatten.']]);
        }

        return (int) round($duration * 1000);
    }

    private function stagingRoot(): string
    {
        $root = (string) config('dis.speech.staging_root', '/opt/dis-data/tts/staging');
        if (! $this->isAbsoluteRoot($root) || is_link($root)
            || ((! is_dir($root) && ! @mkdir($root, 0770, true)) || ! is_writable($root))) {
            throw new \RuntimeException('Speech staging is unavailable.');
        }

        return rtrim($root, '/\\');
    }

    private function isAbsoluteRoot(string $root): bool
    {
        return str_starts_with($root, '/')
            || (! app()->environment('production') && preg_match('/^[A-Za-z]:[\\\\\/]/D', $root) === 1);
    }
}
