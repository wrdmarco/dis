<?php

namespace App\Repositories;

use App\Models\SpeechManifest;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechManifestSegment;
use App\Models\SpeechVoiceProfile;
use Illuminate\Support\Facades\DB;

final class SpeechManifestRepository
{
    public function build(string $id): ?SpeechManifestBuild
    {
        return SpeechManifestBuild::query()->with(['modelInstallation', 'voiceProfile'])->find($id);
    }

    public function manifestForBuild(string $buildId): ?SpeechManifest
    {
        return SpeechManifest::query()->with(['audioAsset', 'segments.audioAsset'])
            ->where('speech_manifest_build_id', $buildId)
            ->first();
    }

    /** @param list<array{sequence:int,semantic_key:string,text:string,text_hmac:string,cache_key:string,audio_asset_id:string,duration_ms:int}> $segments */
    public function seal(array $manifest, array $segments): SpeechManifest
    {
        return DB::transaction(function () use ($manifest, $segments): SpeechManifest {
            if (is_string($manifest['voice_profile_id'] ?? null)) {
                $voice = SpeechVoiceProfile::withTrashed()
                    ->whereKey($manifest['voice_profile_id'])
                    ->lockForUpdate()
                    ->first();
                if ($voice === null || $voice->trashed() || $voice->status !== 'ready'
                    || (int) $voice->consent_version !== (int) ($manifest['voice_consent_version'] ?? 0)) {
                    throw new \RuntimeException('speech_voice_consent_revoked');
                }
            }
            $sealed = SpeechManifest::query()->create($manifest);
            foreach ($segments as $segment) {
                SpeechManifestSegment::query()->create($segment + ['speech_manifest_id' => $sealed->id]);
            }

            return $sealed->load(['audioAsset', 'segments.audioAsset']);
        });
    }
}
