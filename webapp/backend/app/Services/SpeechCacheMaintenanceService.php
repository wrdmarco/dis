<?php

namespace App\Services;

use App\Jobs\RegenerateSpeechCache;
use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechCacheJob;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestSegment;
use App\Models\SpeechPreview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SpeechCacheMaintenanceService
{
    public function __construct(
        private readonly SpeechSettingsService $settings,
        private readonly SpeechTemplateService $templates,
        private readonly SpeechAudioPipeline $audio,
        private readonly AuditService $audit,
    ) {}

    public function start(string $scope, User $actor): SpeechCacheJob
    {
        if (! in_array($scope, ['all', 'segments', 'composites', 'failed'], true)) {
            throw ValidationException::withMessages(['scope' => ['Het gekozen cachebereik is ongeldig.']]);
        }

        return DB::transaction(function () use ($scope, $actor): SpeechCacheJob {
            $active = SpeechCacheJob::query()->whereIn('status', ['queued', 'processing'])->lockForUpdate()->first();
            if ($active !== null) {
                throw ValidationException::withMessages(['scope' => ['Er loopt al een cachebewerking.']]);
            }
            $job = SpeechCacheJob::query()->create([
                'scope' => $scope, 'status' => 'queued', 'progress_percent' => 0, 'requested_by' => $actor->id,
            ]);
            $this->audit->record('speech.cache_regeneration_requested', $job, $actor, ['scope' => $scope]);
            DB::afterCommit(fn () => RegenerateSpeechCache::dispatch((string) $job->id));

            return $job;
        });
    }

    public function run(SpeechCacheJob $job): void
    {
        $runtime = $this->settings->selectedRuntime();
        $resume = $job->status === 'processing' && (int) $job->progress_percent >= 15;
        $job->forceFill([
            'status' => 'processing',
            'progress_percent' => $resume ? (int) $job->progress_percent : 5,
            'error_code' => null,
        ])->save();
        if (! $resume) {
            $this->invalidate((string) $job->scope);
            $job->forceFill(['progress_percent' => 15])->save();
        }

        $phases = $this->templates->phases();
        foreach ($phases as $phaseIndex => $phase) {
            $lines = $this->templates->render(
                $phase,
                $this->templates->template($phase),
                $this->templates->exampleContext($phase),
            );
            $assets = [];
            foreach ($lines as $line) {
                $assets[] = $this->audio->segment($line, $runtime['model'], $runtime['voice'], $runtime['speed']);
            }
            $this->audio->composite($assets, $phase, $runtime['voice']);
            $job->forceFill([
                'progress_percent' => max(
                    (int) $job->progress_percent,
                    min(90, 15 + (int) floor((($phaseIndex + 1) / count($phases)) * 70)),
                ),
            ])->save();
        }
        $this->pruneToQuota();
        $job->forceFill(['status' => 'ready', 'progress_percent' => 100, 'finished_at' => now()])->save();
    }

    public function pruneToQuota(): void
    {
        $quota = max(268_435_456, (int) config('dis.speech.cache_quota_bytes', 5_368_709_120));
        $bytes = (int) SpeechAudioAsset::query()->sum('byte_size');
        if ($bytes > $quota) {
            $protected = $this->protectedAssetIds();
            $entries = SpeechCacheEntry::query()->with('audioAsset')
                ->whereNotNull('audio_asset_id')
                ->when($protected !== [], fn ($query) => $query->whereNotIn('audio_asset_id', $protected))
                ->orderByRaw('last_used_at ASC NULLS FIRST')->oldest()->get();
            foreach ($entries as $entry) {
                if ($bytes <= $quota) {
                    break;
                }
                $size = (int) ($entry->audioAsset?->byte_size ?? 0);
                $entry->delete();
                $this->deleteOrphanAsset((string) $entry->audio_asset_id);
                $bytes -= $size;
            }
        }
        DB::table('speech_cache_counters')->where('id', 1)->update([
            'last_pruned_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function invalidate(string $scope): void
    {
        $protected = $this->protectedAssetIds();
        $query = SpeechCacheEntry::query();
        if ($scope === 'segments') {
            $query->where('category', 'segment');
        } elseif ($scope === 'composites') {
            $query->where('category', 'composite');
        } elseif ($scope === 'failed') {
            $query->where('status', 'failed');
        }
        if ($protected !== []) {
            $query->where(fn ($inner) => $inner->whereNull('audio_asset_id')->orWhereNotIn('audio_asset_id', $protected));
        }
        $assetIds = $query->pluck('audio_asset_id')->filter()->map(fn (mixed $id): string => (string) $id)->all();
        $query->delete();
        foreach ($assetIds as $assetId) {
            $this->deleteOrphanAsset($assetId);
        }
    }

    /** @return list<string> */
    private function protectedAssetIds(): array
    {
        $activeManifestIds = SpeechManifest::query()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('id');

        return collect()
            ->merge(SpeechManifest::query()->whereIn('id', $activeManifestIds)->pluck('audio_asset_id'))
            ->merge(SpeechManifestSegment::query()->whereIn('speech_manifest_id', $activeManifestIds)->pluck('audio_asset_id'))
            ->merge(SpeechPreview::query()->where('expires_at', '>', now())->whereNotNull('audio_asset_id')->pluck('audio_asset_id'))
            ->filter()->map(fn (mixed $id): string => (string) $id)->unique()->values()->all();
    }

    private function deleteOrphanAsset(string $assetId): void
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
            return;
        }
        try {
            $path = $this->audio->verifiedAssetPath($asset);
            @unlink($path);
        } catch (\Throwable) {
            // A missing or integrity-invalid orphan is already absent from the index.
        }
    }
}
