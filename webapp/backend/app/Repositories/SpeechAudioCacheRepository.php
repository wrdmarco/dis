<?php

namespace App\Repositories;

use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechVoiceProfile;
use Illuminate\Support\Facades\DB;

final class SpeechAudioCacheRepository
{
    public function ready(string $cacheKey): ?SpeechCacheEntry
    {
        return SpeechCacheEntry::query()
            ->with('audioAsset')
            ->where('cache_key', $cacheKey)
            ->where('status', 'ready')
            ->first();
    }

    public function recordHit(SpeechCacheEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            SpeechCacheEntry::query()->whereKey($entry->id)->update([
                'hit_count' => DB::raw('hit_count + 1'),
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('speech_cache_counters')->where('id', 1)->update([
                'hit_count' => DB::raw('hit_count + 1'),
                'updated_at' => now(),
            ]);
        });
    }

    public function recordMiss(): void
    {
        DB::table('speech_cache_counters')->where('id', 1)->update([
            'miss_count' => DB::raw('miss_count + 1'),
            'updated_at' => now(),
        ]);
    }

    public function publish(
        string $cacheKey,
        string $category,
        ?string $voiceProfileId,
        string $semanticHmac,
        string $contentSha256,
        string $storagePath,
        int $byteSize,
        int $durationMs,
        \DateTimeInterface $expiresAt,
    ): SpeechCacheEntry {
        return DB::transaction(function () use (
            $cacheKey, $category, $voiceProfileId, $semanticHmac, $contentSha256, $storagePath, $byteSize, $durationMs, $expiresAt,
        ): SpeechCacheEntry {
            if ($voiceProfileId !== null) {
                $voice = SpeechVoiceProfile::withTrashed()->whereKey($voiceProfileId)->lockForUpdate()->first();
                if ($voice === null || $voice->trashed() || $voice->status !== 'ready') {
                    throw new \RuntimeException('speech_voice_consent_revoked');
                }
            }
            $asset = SpeechAudioAsset::query()->firstOrCreate(
                ['content_sha256' => $contentSha256],
                [
                    'storage_path' => $storagePath,
                    'mime_type' => 'audio/mp4',
                    'byte_size' => $byteSize,
                    'duration_ms' => $durationMs,
                ],
            );
            $entry = SpeechCacheEntry::query()->updateOrCreate(
                ['cache_key' => $cacheKey],
                [
                    'category' => $category,
                    'audio_asset_id' => $asset->id,
                    'voice_profile_id' => $voiceProfileId,
                    'semantic_hmac' => $semanticHmac,
                    'status' => 'ready',
                    'error_code' => null,
                    'last_used_at' => now(),
                    'expires_at' => $expiresAt,
                ],
            );

            return $entry->load('audioAsset');
        });
    }
}
