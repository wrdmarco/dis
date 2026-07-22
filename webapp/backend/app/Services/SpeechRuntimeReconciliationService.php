<?php

namespace App\Services;

use App\Contracts\SpeechEngineClient;
use App\Jobs\RegenerateSpeechCache;
use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechCacheJob;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechManifestSegment;
use App\Models\SpeechModelInstallation;
use App\Models\SpeechPreview;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SpeechRuntimeReconciliationService
{
    public function __construct(
        private readonly SpeechEngineClient $engine,
        private readonly SpeechAudioPipeline $audio,
    ) {}

    /** @return array{models_invalidated:int,audio_invalidated:int,regeneration_queued:bool} */
    public function reconcile(): array
    {
        $modelsInvalidated = 0;
        foreach (SpeechModelInstallation::query()->whereIn('status', ['installing', 'installed'])->get() as $installation) {
            try {
                $status = $this->engine->status((string) $installation->catalog_key);
                $revision = $status['installed_revision'] ?? $status['revision'] ?? null;
                $valid = ($status['status'] ?? null) === 'installed'
                    && is_string($revision)
                    && $revision === $installation->revision
                    && is_string($status['weights_sha256'] ?? null)
                    && hash_equals((string) $installation->weights_sha256, $status['weights_sha256']);
            } catch (Throwable) {
                $valid = false;
            }
            if ($valid && $installation->status === 'installing') {
                $installation->forceFill([
                    'status' => 'installed',
                    'progress_percent' => 100,
                    'error_code' => null,
                    'installed_at' => now(),
                    'failed_at' => null,
                ])->save();
            } elseif (! $valid) {
                $installation->forceFill([
                    'status' => 'failed',
                    'progress_percent' => 0,
                    'error_code' => 'installed_model_unverified_after_restore',
                    'installed_at' => null,
                    'failed_at' => now(),
                ])->save();
                $modelsInvalidated++;
            }
        }
        DB::table('speech_runtime_states')->where('id', 1)->update([
            'active_installation_id' => null,
            'active_model_id' => null,
            'install_claim_token' => null,
            'install_started_at' => null,
            'install_cancel_requested_at' => null,
            'updated_at' => now(),
        ]);

        $invalidAssetIds = [];
        foreach (SpeechAudioAsset::query()->cursor() as $asset) {
            try {
                $this->audio->verifiedAssetPath($asset);
            } catch (Throwable) {
                $invalidAssetIds[] = (string) $asset->id;
            }
        }
        $invalidAssetIds = array_values(array_unique($invalidAssetIds));
        if ($invalidAssetIds !== []) {
            DB::transaction(function () use ($invalidAssetIds): void {
                $manifestIds = collect()
                    ->merge(SpeechManifest::query()->whereIn('audio_asset_id', $invalidAssetIds)->pluck('id'))
                    ->merge(SpeechManifestSegment::query()->whereIn('audio_asset_id', $invalidAssetIds)->pluck('speech_manifest_id'))
                    ->unique()->values();
                $buildIds = SpeechManifest::query()->whereIn('id', $manifestIds)->pluck('speech_manifest_build_id');
                SpeechCacheEntry::query()->whereIn('audio_asset_id', $invalidAssetIds)->delete();
                SpeechPreview::query()->whereIn('audio_asset_id', $invalidAssetIds)->update([
                    'status' => 'failed',
                    'error_code' => 'speech_audio_missing_after_restore',
                    'audio_asset_id' => null,
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);
                SpeechManifestBuild::query()->whereIn('id', $buildIds)->update([
                    'status' => 'failed',
                    'error_code' => 'speech_audio_missing_after_restore',
                    'failed_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }

        $regenerationQueued = $invalidAssetIds !== [] && $this->queueRegenerationWhenRuntimeIsReady();

        return [
            'models_invalidated' => $modelsInvalidated,
            'audio_invalidated' => count($invalidAssetIds),
            'regeneration_queued' => $regenerationQueued,
        ];
    }

    private function queueRegenerationWhenRuntimeIsReady(): bool
    {
        if (! SystemSetting::boolean('speech.enabled', false)) {
            return false;
        }
        $modelId = SystemSetting::string('speech.model_id');
        if ($modelId === null || ! SpeechModelInstallation::query()
            ->where('catalog_key', $modelId)->where('status', 'installed')->exists()) {
            return false;
        }

        return DB::transaction(function (): bool {
            if (SpeechCacheJob::query()->whereIn('status', ['queued', 'processing'])->lockForUpdate()->exists()) {
                return false;
            }
            $job = SpeechCacheJob::query()->create([
                'scope' => 'all',
                'status' => 'queued',
                'progress_percent' => 0,
                'requested_by' => null,
            ]);
            DB::afterCommit(fn () => RegenerateSpeechCache::dispatch((string) $job->id));

            return true;
        });
    }
}
