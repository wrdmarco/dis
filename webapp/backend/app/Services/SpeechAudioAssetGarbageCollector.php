<?php

namespace App\Services;

use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestSegment;
use App\Models\SpeechPreview;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SplFileInfo;

final class SpeechAudioAssetGarbageCollector
{
    private const BATCH_SIZE = 250;

    public function markIfUnreferenced(string $assetId): bool
    {
        return DB::transaction(function () use ($assetId): bool {
            $asset = SpeechAudioAsset::query()->whereKey($assetId)->lockForUpdate()->first();
            if ($asset === null) {
                return false;
            }
            if ($this->isReferenced($assetId)) {
                if ($asset->orphaned_at !== null) {
                    $asset->forceFill(['orphaned_at' => null])->save();
                }

                return false;
            }
            if ($asset->orphaned_at === null) {
                $asset->forceFill(['orphaned_at' => now()])->save();
            }

            return true;
        });
    }

    public function restoreReference(string $assetId): void
    {
        SpeechAudioAsset::query()
            ->whereKey($assetId)
            ->whereNotNull('orphaned_at')
            ->update([
                'orphaned_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function collectExpired(): int
    {
        $graceSeconds = max(3600, (int) config('dis.speech.orphan_grace_seconds', 86_400));
        $cutoff = now()->subSeconds($graceSeconds);

        return Cache::lock('speech-cache-quota-publish', 300)->block(
            30,
            function () use ($cutoff): int {
                $this->discoverUntrackedOrphans($cutoff);
                $ids = SpeechAudioAsset::query()
                    ->whereNotNull('orphaned_at')
                    ->where('orphaned_at', '<=', $cutoff)
                    ->orderBy('orphaned_at')
                    ->limit(self::BATCH_SIZE)
                    ->pluck('id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->all();
                $removed = 0;
                foreach ($ids as $id) {
                    $asset = DB::transaction(function () use ($id): ?SpeechAudioAsset {
                        $asset = SpeechAudioAsset::query()->whereKey($id)->lockForUpdate()->first();
                        if ($asset === null) {
                            return null;
                        }
                        if ($this->isReferenced($id)) {
                            $asset->forceFill(['orphaned_at' => null])->save();

                            return null;
                        }
                        $asset->delete();

                        return $asset;
                    });
                    if ($asset === null) {
                        continue;
                    }
                    $this->deleteIndexedBytes($asset);
                    $removed++;
                }
                $this->deleteUnindexedBytes($cutoff->getTimestamp());

                return $removed;
            },
        );
    }

    private function discoverUntrackedOrphans(\DateTimeInterface $cutoff): void
    {
        $ids = SpeechAudioAsset::query()
            ->whereNull('orphaned_at')
            ->where('created_at', '<=', $cutoff)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('speech_cache_entries')
                ->whereColumn('speech_cache_entries.audio_asset_id', 'speech_audio_assets.id'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('speech_manifests')
                ->whereColumn('speech_manifests.audio_asset_id', 'speech_audio_assets.id'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('speech_manifest_segments')
                ->whereColumn('speech_manifest_segments.audio_asset_id', 'speech_audio_assets.id'))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('speech_previews')
                ->whereColumn('speech_previews.audio_asset_id', 'speech_audio_assets.id'))
            ->orderBy('created_at')
            ->limit(self::BATCH_SIZE)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        foreach ($ids as $id) {
            $this->markIfUnreferenced($id);
        }
    }

    private function isReferenced(string $assetId): bool
    {
        return SpeechCacheEntry::query()->where('audio_asset_id', $assetId)->exists()
            || SpeechManifest::query()->where('audio_asset_id', $assetId)->exists()
            || SpeechManifestSegment::query()->where('audio_asset_id', $assetId)->exists()
            || SpeechPreview::query()->where('audio_asset_id', $assetId)->exists();
    }

    private function deleteIndexedBytes(SpeechAudioAsset $asset): void
    {
        $path = $this->absoluteObjectPath((string) $asset->storage_path);
        if ($path !== null && is_file($path) && ! is_link($path)) {
            @unlink($path);
        }
    }

    private function deleteUnindexedBytes(int $cutoff): void
    {
        $objects = $this->objectsRoot();
        if ($objects === null) {
            return;
        }
        foreach ($this->directoryEntries($objects) as $shard) {
            if (! preg_match('/^[a-f0-9]{2}$/D', $shard->getFilename())
                || ! $this->safeDirectory($shard->getPathname())) {
                continue;
            }
            foreach ($this->directoryEntries($shard->getPathname()) as $entry) {
                $name = $entry->getFilename();
                if (! preg_match('/^([a-f0-9]{64})\.m4a$/D', $name)
                    || $entry->isLink()
                    || ! $entry->isFile()
                    || $entry->getMTime() > $cutoff) {
                    continue;
                }
                $relative = 'objects/'.$shard->getFilename().'/'.$name;
                if (! SpeechAudioAsset::query()->where('storage_path', $relative)->exists()) {
                    @unlink($entry->getPathname());
                }
            }
        }
    }

    private function absoluteObjectPath(string $relative): ?string
    {
        if (preg_match('#^objects/[a-f0-9]{2}/[a-f0-9]{64}\.m4a$#D', $relative) !== 1) {
            return null;
        }
        $root = $this->cacheRoot();

        return $root === null
            ? null
            : $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function objectsRoot(): ?string
    {
        $root = $this->cacheRoot();
        if ($root === null) {
            return null;
        }
        $objects = $root.DIRECTORY_SEPARATOR.'objects';

        return $this->safeDirectory($objects) ? $objects : null;
    }

    private function cacheRoot(): ?string
    {
        $root = rtrim((string) config('dis.speech.cache_root', '/opt/dis-data/tts/cache'), '/\\');
        if ((! str_starts_with($root, '/')
                && (app()->environment('production') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $root) !== 1))
            || is_link($root)
            || ! is_dir($root)) {
            return null;
        }

        return $root;
    }

    private function safeDirectory(string $path): bool
    {
        return is_dir($path) && ! is_link($path);
    }

    /** @return list<SplFileInfo> */
    private function directoryEntries(string $path): array
    {
        try {
            $iterator = new \FilesystemIterator(
                $path,
                \FilesystemIterator::CURRENT_AS_FILEINFO
                    | \FilesystemIterator::SKIP_DOTS,
            );

            return array_values(iterator_to_array($iterator, false));
        } catch (\Throwable) {
            return [];
        }
    }
}
