<?php

namespace App\Repositories;

use App\DTO\SpeechCacheEntryMetadata;
use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechVoiceProfile;
use App\Services\SpeechAudioAssetGarbageCollector;
use Illuminate\Support\Facades\DB;

final class SpeechAudioCacheRepository
{
    public function __construct(
        private readonly SpeechAudioAssetGarbageCollector $garbageCollector,
    ) {}

    public function ready(string $cacheKey): ?SpeechCacheEntry
    {
        return SpeechCacheEntry::query()
            ->with('audioAsset')
            ->where('cache_key', $cacheKey)
            ->where('status', 'ready')
            ->first();
    }

    public function recordHit(SpeechCacheEntry $entry, SpeechCacheEntryMetadata $metadata): void
    {
        DB::transaction(function () use ($entry, $metadata): void {
            if ($this->metadataIsIncomplete($entry)) {
                $managed = SpeechCacheEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
                $missing = $this->missingMetadata($managed, $metadata);
                if ($missing !== []) {
                    $managed->forceFill($missing)->save();
                }
            }
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
        SpeechCacheEntryMetadata $metadata,
    ): SpeechCacheEntry {
        return DB::transaction(function () use (
            $cacheKey, $category, $voiceProfileId, $semanticHmac, $contentSha256, $storagePath, $byteSize, $durationMs, $expiresAt,
            $metadata,
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
            $this->garbageCollector->restoreReference((string) $asset->id);
            $previousAssetId = SpeechCacheEntry::query()
                ->where('cache_key', $cacheKey)
                ->lockForUpdate()
                ->value('audio_asset_id');
            $entry = SpeechCacheEntry::query()->updateOrCreate(
                ['cache_key' => $cacheKey],
                $this->metadata($metadata) + [
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
            if ($previousAssetId !== null && (string) $previousAssetId !== (string) $asset->id) {
                $this->garbageCollector->markIfUnreferenced((string) $previousAssetId);
            }

            return $entry->load('audioAsset');
        });
    }

    /** @return array<string, mixed> */
    private function metadata(SpeechCacheEntryMetadata $metadata): array
    {
        return [
            'display_text' => $metadata->text,
            'locale' => $metadata->locale,
            'model_catalog_key' => $metadata->modelCatalogKey,
            'model_revision' => $metadata->modelRevision,
            'voice_design_revision' => $metadata->voiceDesignRevision,
            'audio_recipe_revision' => $metadata->audioRecipeRevision,
            'speed' => $metadata->speed,
        ];
    }

    private function metadataIsIncomplete(SpeechCacheEntry $entry): bool
    {
        foreach (array_keys($this->metadataColumns($entry)) as $column) {
            if ($entry->getRawOriginal($column) === null) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function missingMetadata(
        SpeechCacheEntry $entry,
        SpeechCacheEntryMetadata $metadata,
    ): array {
        $values = $this->metadata($metadata);
        $missing = [];
        foreach (array_keys($this->metadataColumns($entry)) as $column) {
            if ($entry->getRawOriginal($column) === null) {
                $missing[$column] = $values[$column];
            }
        }

        return $missing;
    }

    /** @return array<string, true> */
    private function metadataColumns(SpeechCacheEntry $entry): array
    {
        $columns = [
            'display_text' => true,
            'locale' => true,
            'model_catalog_key' => true,
            'model_revision' => true,
            'audio_recipe_revision' => true,
            'speed' => true,
        ];
        if ($entry->voice_profile_id === null) {
            $columns['voice_design_revision'] = true;
        }

        return $columns;
    }
}
