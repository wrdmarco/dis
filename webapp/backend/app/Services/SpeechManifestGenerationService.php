<?php

namespace App\Services;

use App\Models\SpeechManifest;
use App\Models\SpeechManifestBuild;
use App\Repositories\SpeechManifestRepository;

final class SpeechManifestGenerationService
{
    public function __construct(
        private readonly SpeechAudioPipeline $audio,
        private readonly SpeechCacheKeyService $keys,
        private readonly SpeechManifestRepository $manifests,
    ) {}

    /** @param null|callable(int):void $progress */
    public function generate(SpeechManifestBuild $build, ?callable $progress = null): SpeechManifest
    {
        $existing = $this->manifests->manifestForBuild((string) $build->id);
        if ($existing !== null) {
            return $existing;
        }
        $build->loadMissing(['modelInstallation', 'voiceProfile']);
        $voiceDesignRevision = trim((string) $build->voice_design_revision);
        $profileInvalid = $build->voice_profile_id !== null
            && ($build->voiceProfile === null || $build->voiceProfile->status !== 'ready');
        $designInvalid = $build->voice_profile_id === null
            && ($voiceDesignRevision === ''
                || config('dis.speech.models.'.$build->modelInstallation?->catalog_key.'.capabilities.voice_design') !== true
                || $voiceDesignRevision !== (string) config(
                    'dis.speech.models.'.$build->modelInstallation?->catalog_key.'.built_in_voice_design_revision',
                    '',
                ));
        if ($build->modelInstallation === null || $build->modelInstallation->status !== 'installed'
            || $profileInvalid || $designInvalid) {
            throw new \RuntimeException('speech_configuration_missing');
        }
        $lines = array_values((array) $build->rendered_lines);
        if ($lines === [] || count($lines) > 8) {
            throw new \RuntimeException('invalid_rendered_lines');
        }
        $build->forceFill(['status' => 'processing', 'progress_percent' => 5, 'error_code' => null])->save();
        if ($progress !== null) {
            $progress(5);
        }
        $assets = [];
        $segmentRows = [];
        foreach ($lines as $index => $line) {
            if (! is_string($line) || trim($line) === '') {
                throw new \RuntimeException('invalid_rendered_line');
            }
            $asset = $this->audio->segment(
                $line,
                $build->modelInstallation,
                $build->voiceProfile,
                (float) $build->speed,
            );
            $assets[] = $asset;
            $segmentRows[] = [
                'sequence' => $index,
                'semantic_key' => 'line_'.($index + 1),
                'text' => $line,
                'text_hmac' => $this->keys->semantic($line),
                'cache_key' => $this->audio->segmentCacheKey(
                    $line,
                    $build->modelInstallation,
                    $build->voiceProfile,
                    (float) $build->speed,
                ),
                'audio_asset_id' => $asset->id,
                'duration_ms' => (int) $asset->duration_ms,
            ];
            $percent = min(80, 10 + (int) floor((($index + 1) / count($lines)) * 65));
            $build->forceFill(['progress_percent' => $percent])->save();
            if ($progress !== null) {
                $progress($percent);
            }
        }
        $composite = $this->audio->composite($assets, (string) $build->phase, $build->voiceProfile);
        $manifest = $this->manifests->seal([
            'speech_manifest_build_id' => $build->id,
            'dispatch_request_id' => $build->dispatch_request_id,
            'dispatch_recipient_id' => $build->dispatch_recipient_id,
            'phase' => $build->phase,
            'locale' => 'nl-NL',
            'model_catalog_key' => $build->modelInstallation->catalog_key,
            'model_revision' => $build->modelInstallation->revision,
            'model_weights_sha256' => $build->modelInstallation->weights_sha256,
            'voice_profile_id' => $build->voiceProfile?->id,
            'voice_consent_version' => $build->voiceProfile?->consent_version,
            'voice_design_revision' => $build->voice_profile_id === null ? $voiceDesignRevision : null,
            'speed' => $build->speed,
            'template_checksum' => $build->template_checksum,
            'context_hmac' => $build->context_hmac,
            'manifest_sha256' => hash('sha256', $build->id.'|'.$composite->content_sha256),
            'audio_asset_id' => $composite->id,
            'segment_count' => count($segmentRows),
            'duration_ms' => $composite->duration_ms,
            'expires_at' => $build->expires_at,
            'sealed_at' => now(),
            'created_at' => now(),
        ], $segmentRows);
        $build->forceFill([
            'status' => 'ready', 'progress_percent' => 100, 'finished_at' => now(), 'error_code' => null,
        ])->save();
        if ($progress !== null) {
            $progress(100);
        }

        return $manifest;
    }
}
