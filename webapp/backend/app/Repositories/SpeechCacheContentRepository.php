<?php

namespace App\Repositories;

use App\Models\SpeechCacheEntry;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestSegment;
use App\Models\SpeechPreview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class SpeechCacheContentRepository
{
    public function paginateForManagement(
        ?string $category,
        ?string $status,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->query($category, $status)->paginate($perPage, ['*'], 'page', $page);
    }

    public function searchExceedsLimit(?string $category, ?string $status, int $limit): bool
    {
        return $this->query($category, $status, false)
            ->reorder('id')
            ->offset($limit)
            ->limit(1)
            ->value('id') !== null;
    }

    /** @return Collection<int, SpeechCacheEntry> */
    public function entriesForBoundedSearch(?string $category, ?string $status, int $limit): Collection
    {
        return $this->query($category, $status)->limit($limit)->get();
    }

    /** @return Builder<SpeechCacheEntry> */
    private function query(?string $category, ?string $status, bool $withRelations = true): Builder
    {
        $query = SpeechCacheEntry::query()
            ->select([
                'id',
                'category',
                'audio_asset_id',
                'voice_profile_id',
                'display_text',
                'locale',
                'model_catalog_key',
                'model_revision',
                'voice_design_revision',
                'audio_recipe_revision',
                'speed',
                'synthesis_duration_ms',
                'status',
                'error_code',
                'hit_count',
                'last_used_at',
                'expires_at',
                'created_at',
                'updated_at',
                'cache_key',
            ])
            ->whereIn('category', ['segment', 'composite'])
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->when($status === 'expired', fn ($query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()))
            ->when($status !== null && $status !== 'expired', function ($query) use ($status): void {
                $query
                    ->where('status', $status)
                    ->where(fn ($expiry) => $expiry
                        ->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now()));
            })
            ->orderByRaw('last_used_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->orderBy('id');

        if ($withRelations) {
            $query->with([
                'audioAsset:id,mime_type,byte_size,duration_ms,created_at,updated_at',
                'voiceProfile:id,name,locale,status,consent_version',
            ]);
        }

        return $query;
    }

    public function entryForAudio(string $id): ?SpeechCacheEntry
    {
        return SpeechCacheEntry::query()
            ->with(['audioAsset', 'voiceProfile'])
            ->find($id);
    }

    /**
     * @param  Collection<int, SpeechCacheEntry>  $entries
     * @return array<string, array<string, mixed>>
     */
    public function legacyContexts(Collection $entries): array
    {
        $entries = $entries
            ->filter(fn (SpeechCacheEntry $entry): bool => $this->needsLegacyContext($entry))
            ->values();
        if ($entries->isEmpty()) {
            return [];
        }

        $contexts = [];
        foreach ($entries->chunk(500) as $chunk) {
            $contexts += $this->legacyContextsForChunk($chunk);
        }

        return $contexts;
    }

    /**
     * @param  Collection<int, SpeechCacheEntry>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function legacyContextsForChunk(Collection $entries): array
    {
        $cacheKeys = $entries
            ->pluck('cache_key')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
        $assetIds = $entries
            ->pluck('audio_asset_id')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        $segmentsByCacheKey = $cacheKeys === [] ? collect() : SpeechManifestSegment::query()
            ->with(['manifest.voiceProfile'])
            ->whereIn('cache_key', $cacheKeys)
            ->distinct('cache_key')
            ->orderBy('cache_key')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy('cache_key');
        $manifestsByAsset = $assetIds === [] ? collect() : SpeechManifest::query()
            ->with(['segments', 'voiceProfile'])
            ->whereIn('audio_asset_id', $assetIds)
            ->distinct('audio_asset_id')
            ->orderBy('audio_asset_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy('audio_asset_id');
        $previewsByAsset = $assetIds === [] ? collect() : SpeechPreview::query()
            ->with([
                'manifest.segments',
                'manifest.voiceProfile',
                'build.modelInstallation',
                'build.voiceProfile',
            ])
            ->whereIn('audio_asset_id', $assetIds)
            ->distinct('audio_asset_id')
            ->orderBy('audio_asset_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy('audio_asset_id');

        $contexts = [];
        foreach ($entries as $entry) {
            $segment = $segmentsByCacheKey->get((string) $entry->cache_key);
            if ($segment instanceof SpeechManifestSegment && $segment->manifest instanceof SpeechManifest) {
                $contexts[(string) $entry->id] = $this->manifestContext(
                    $segment->manifest,
                    $this->safeText($segment),
                );

                continue;
            }

            $manifest = $manifestsByAsset->get((string) $entry->audio_asset_id);
            if ($manifest instanceof SpeechManifest) {
                $contexts[(string) $entry->id] = $this->manifestContext(
                    $manifest,
                    $this->manifestText($manifest),
                );

                continue;
            }

            $preview = $previewsByAsset->get((string) $entry->audio_asset_id);
            if ($preview instanceof SpeechPreview) {
                $contexts[(string) $entry->id] = $this->previewContext($preview);
            }
        }

        return $contexts;
    }

    private function needsLegacyContext(SpeechCacheEntry $entry): bool
    {
        foreach ([
            'display_text',
            'locale',
            'model_catalog_key',
            'model_revision',
            'audio_recipe_revision',
            'speed',
        ] as $attribute) {
            if ($entry->getRawOriginal($attribute) === null) {
                return true;
            }
        }

        return $entry->voice_profile_id === null
            && $entry->getRawOriginal('voice_design_revision') === null;
    }

    /** @return array<string, mixed> */
    private function manifestContext(SpeechManifest $manifest, ?string $text): array
    {
        return [
            'text' => $text,
            'text_source' => $text === null ? null : 'manifest',
            'locale' => $this->string($manifest->locale),
            'model_catalog_key' => $this->string($manifest->model_catalog_key),
            'model_revision' => $this->string($manifest->model_revision),
            'voice_profile' => $manifest->voiceProfile,
            'voice_design_revision' => $this->string($manifest->voice_design_revision),
            'audio_recipe_revision' => $this->string($manifest->audio_recipe_revision),
            'speed' => is_numeric($manifest->speed) ? (float) $manifest->speed : null,
        ];
    }

    /** @return array<string, mixed> */
    private function previewContext(SpeechPreview $preview): array
    {
        $previewText = $this->previewText($preview);
        if ($preview->manifest instanceof SpeechManifest) {
            $context = $this->manifestContext(
                $preview->manifest,
                $previewText ?? $this->manifestText($preview->manifest),
            );
            $context['text_source'] = $context['text'] === null ? null : 'preview';

            return $context;
        }

        $build = $preview->build;

        return [
            'text' => $previewText,
            'text_source' => $previewText === null ? null : 'preview',
            'locale' => $this->string($build?->locale),
            'model_catalog_key' => $this->string($build?->modelInstallation?->catalog_key),
            'model_revision' => $this->string($build?->modelInstallation?->revision),
            'voice_profile' => $build?->voiceProfile,
            'voice_design_revision' => $this->string($build?->voice_design_revision),
            'audio_recipe_revision' => $this->string($build?->audio_recipe_revision),
            'speed' => is_numeric($build?->speed) ? (float) $build->speed : null,
        ];
    }

    private function manifestText(SpeechManifest $manifest): ?string
    {
        try {
            $lines = $manifest->segments
                ->sortBy('sequence')
                ->map(fn (SpeechManifestSegment $segment): mixed => $segment->text)
                ->filter(fn (mixed $line): bool => is_string($line) && trim($line) !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return null;
        }

        return $this->lines($lines);
    }

    private function previewText(SpeechPreview $preview): ?string
    {
        try {
            return $this->lines((array) $preview->rendered_lines);
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeText(SpeechManifestSegment $segment): ?string
    {
        try {
            return is_string($segment->text) && trim($segment->text) !== ''
                ? $segment->text
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<int, mixed> $lines */
    private function lines(array $lines): ?string
    {
        $valid = array_values(array_filter(
            $lines,
            static fn (mixed $line): bool => is_string($line) && trim($line) !== '',
        ));

        return $valid === [] ? null : implode("\n", $valid);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
