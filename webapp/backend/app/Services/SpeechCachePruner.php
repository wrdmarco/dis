<?php

namespace App\Services;

use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestSegment;
use App\Models\SpeechPreview;
use Illuminate\Support\Facades\DB;

final class SpeechCachePruner
{
    public function pruneExpiredAndQuota(): void
    {
        $this->pruneStaleRuntimeFiles();
        $protected = $this->protectedAssetIds();
        $expired = SpeechCacheEntry::query()->where('expires_at', '<=', now())
            ->when($protected !== [], fn ($query) => $query->where(fn ($inner) => $inner
                ->whereNull('audio_asset_id')->orWhereNotIn('audio_asset_id', $protected)))
            ->pluck('id');
        $assetIds = SpeechCacheEntry::query()->whereIn('id', $expired)->pluck('audio_asset_id')
            ->filter()->map(fn (mixed $id): string => (string) $id)->all();
        SpeechCacheEntry::query()->whereIn('id', $expired)->delete();
        foreach ($assetIds as $assetId) {
            $this->deleteOrphanAsset($assetId);
        }
        $this->ensureCapacity(0);
        DB::table('speech_cache_counters')->where('id', 1)->update([
            'last_pruned_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function pruneStaleRuntimeFiles(): void
    {
        $cutoff = now()->subHours(24)->getTimestamp();
        $staging = rtrim((string) config('dis.speech.staging_root', '/opt/dis-data/tts/staging'), '/\\');
        if ($this->safeDirectory($staging)) {
            foreach ($this->directoryEntries($staging) as $entry) {
                $name = $entry->getFilename();
                $path = $entry->getPathname();
                if (preg_match('/^(?:[0-9A-HJKMNP-TV-Z]{26}\.(?:job\.json|reference|wav|m4a|wav\.part)|\.engine-reference-[A-Za-z0-9_-]{6,80}\.audio)$/D', $name) === 1) {
                    $this->deleteOldRegularFile($path, $cutoff);
                } elseif (preg_match('/^\.voice-([0-9A-HJKMNP-TV-Z]{26})$/D', $name, $matches) === 1) {
                    $this->deleteOldVoiceDirectory($path, $matches[1], $cutoff);
                }
            }
        }

        $objects = rtrim((string) config('dis.speech.cache_root', '/opt/dis-data/tts/cache'), '/\\')
            .DIRECTORY_SEPARATOR.'objects';
        if (! $this->safeDirectory($objects)) {
            return;
        }
        foreach ($this->directoryEntries($objects) as $shard) {
            if (! preg_match('/^[a-f0-9]{2}$/D', $shard->getFilename()) || ! $this->safeDirectory($shard->getPathname())) {
                continue;
            }
            foreach ($this->directoryEntries($shard->getPathname()) as $entry) {
                if (preg_match('/^[a-f0-9]{64}\.m4a\.[0-9A-HJKMNP-TV-Z]{26}\.part$/D', $entry->getFilename()) === 1) {
                    $this->deleteOldRegularFile($entry->getPathname(), $cutoff);
                }
            }
        }
    }

    public function ensureCapacity(int $additionalBytes): void
    {
        $quota = max(268_435_456, (int) config('dis.speech.cache_quota_bytes', 5_368_709_120));
        $bytes = (int) SpeechAudioAsset::query()->sum('byte_size');
        if ($bytes + max(0, $additionalBytes) <= $quota) {
            return;
        }
        $protected = $this->protectedAssetIds();
        $entries = SpeechCacheEntry::query()->with('audioAsset')
            ->whereNotNull('audio_asset_id')
            ->when($protected !== [], fn ($query) => $query->whereNotIn('audio_asset_id', $protected))
            ->orderByRaw('last_used_at ASC NULLS FIRST')->oldest()->get();
        foreach ($entries as $entry) {
            if ($bytes + $additionalBytes <= $quota) {
                break;
            }
            $assetId = (string) $entry->audio_asset_id;
            $entry->delete();
            $bytes -= $this->deleteOrphanAsset($assetId);
        }
        if ((int) SpeechAudioAsset::query()->sum('byte_size') + $additionalBytes > $quota) {
            throw new \RuntimeException('speech_cache_quota_exceeded');
        }
    }

    /** @return list<string> */
    private function protectedAssetIds(): array
    {
        $manifests = SpeechManifest::query()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('id');

        return collect()
            ->merge(SpeechManifest::query()->whereIn('id', $manifests)->pluck('audio_asset_id'))
            ->merge(SpeechManifestSegment::query()->whereIn('speech_manifest_id', $manifests)->pluck('audio_asset_id'))
            ->merge(SpeechPreview::query()->where('expires_at', '>', now())->whereNotNull('audio_asset_id')->pluck('audio_asset_id'))
            ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
    }

    private function deleteOrphanAsset(string $assetId): int
    {
        $asset = DB::transaction(function () use ($assetId): ?SpeechAudioAsset {
            $asset = SpeechAudioAsset::query()->whereKey($assetId)->lockForUpdate()->first();
            if ($asset === null
                || SpeechCacheEntry::query()->where('audio_asset_id', $assetId)->exists()
                || SpeechManifest::query()->where('audio_asset_id', $assetId)->exists()
                || SpeechManifestSegment::query()->where('audio_asset_id', $assetId)->exists()
                || SpeechPreview::query()->where('audio_asset_id', $assetId)->exists()) {
                return null;
            }
            $asset->delete();

            return $asset;
        });
        if ($asset === null) {
            return 0;
        }
        $relative = (string) $asset->storage_path;
        if (preg_match('#^objects/[a-f0-9]{2}/[a-f0-9]{64}\.m4a$#D', $relative) === 1) {
            $root = rtrim((string) config('dis.speech.cache_root', '/opt/dis-data/tts/cache'), '/\\');
            if (str_starts_with($root, '/') || (! app()->environment('production') && preg_match('/^[A-Za-z]:[\\\\\/]/D', $root) === 1)) {
                $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (is_file($path) && ! is_link($path)) {
                    @unlink($path);
                }
            }
        }

        return (int) $asset->byte_size;
    }

    private function safeDirectory(string $path): bool
    {
        $metadata = @lstat($path);

        return is_array($metadata)
            && ! is_link($path)
            && is_dir($path)
            && is_readable($path)
            && (((int) ($metadata['mode'] ?? 0)) & 0170000) === 0040000;
    }

    private function deleteOldRegularFile(string $path, int $cutoff): void
    {
        $metadata = @lstat($path);
        if (! is_array($metadata) || is_link($path)
            || (((int) ($metadata['mode'] ?? 0)) & 0170000) !== 0100000
            || (int) ($metadata['mtime'] ?? PHP_INT_MAX) > $cutoff) {
            return;
        }
        @unlink($path);
    }

    private function deleteOldVoiceDirectory(string $path, string $ulid, int $cutoff): void
    {
        $metadata = @lstat($path);
        if (! $this->safeDirectory($path) || (int) ($metadata['mtime'] ?? PHP_INT_MAX) > $cutoff) {
            return;
        }
        $files = $this->directoryEntries($path);
        if (count($files) > 1) {
            return;
        }
        foreach ($files as $file) {
            if ($file->getFilename() !== $ulid.'.wav') {
                return;
            }
            $fileMetadata = @lstat($file->getPathname());
            if (! is_array($fileMetadata) || (int) ($fileMetadata['mtime'] ?? PHP_INT_MAX) > $cutoff
                || is_link($file->getPathname())
                || (((int) ($fileMetadata['mode'] ?? 0)) & 0170000) !== 0100000) {
                return;
            }
        }
        foreach ($files as $file) {
            @unlink($file->getPathname());
        }
        @rmdir($path);
    }

    /** @return list<\SplFileInfo> */
    private function directoryEntries(string $path): array
    {
        try {
            return array_values(iterator_to_array(
                new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS),
                false,
            ));
        } catch (\UnexpectedValueException) {
            return [];
        }
    }
}
