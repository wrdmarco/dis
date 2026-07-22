<?php

namespace App\Services;

use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechCacheJob;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechModelInstallation;
use App\Models\SpeechPreview;
use App\Models\SpeechVoiceProfile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repositories\SpeechModelInstallationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SpeechSettingsService
{
    public function __construct(
        private readonly SpeechTemplateService $templates,
        private readonly SpeechModelCatalog $catalog,
        private readonly SpeechModelInstallationRepository $installations,
        private readonly AuditService $audit,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'settings' => $this->settings(),
            'template_definitions' => $this->templates->definitions(),
            'models' => $this->catalog->summaries(),
            'voice_profiles' => SpeechVoiceProfile::query()->latest()->get()
                ->map(fn (SpeechVoiceProfile $profile): array => $this->voiceProfile($profile))->values()->all(),
            'cache' => $this->cacheStatus(),
        ];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $modelId = SystemSetting::string('speech.model_id');
        $voiceProfileId = SystemSetting::string('speech.voice_profile_id');

        return [
            'enabled' => SystemSetting::boolean('speech.enabled', false),
            'model_id' => $modelId,
            'voice_profile_id' => $voiceProfileId,
            'speed' => round(max(0.85, min(1.15, (float) SystemSetting::value('speech.speed', 1.0))), 2),
            'pre_generate_on_save' => SystemSetting::boolean('speech.pre_generate_on_save', true),
            'templates' => $this->templates->settings(),
        ];
    }

    /** @return array{model:SpeechModelInstallation,voice:?SpeechVoiceProfile,voice_design_revision:?string,speed:float} */
    public function selectedRuntime(): array
    {
        $settings = $this->settings();
        $model = $settings['model_id'] === null ? null
            : $this->installations->installedForCatalog((string) $settings['model_id']);
        $voice = $settings['voice_profile_id'] === null ? null : SpeechVoiceProfile::query()
            ->whereKey($settings['voice_profile_id'])->where('status', 'ready')->first();
        if ($model === null) {
            throw ValidationException::withMessages(['model_id' => ['Kies en installeer eerst een spraakmodel.']]);
        }
        if ($settings['voice_profile_id'] !== null && $voice === null) {
            throw ValidationException::withMessages(['voice_profile_id' => ['Het gekozen stemprofiel is niet meer gereed.']]);
        }
        if ($voice !== null && ! $this->catalog->acceptsVoiceProfile((string) $settings['model_id'])) {
            throw ValidationException::withMessages(['voice_profile_id' => ['Het gekozen model ondersteunt geen eigen stemprofiel.']]);
        }
        $designRevision = $settings['voice_profile_id'] === null
            ? $this->catalog->builtInVoiceDesignRevision((string) $settings['model_id'])
            : null;
        if ($voice === null && $designRevision === null) {
            throw ValidationException::withMessages(['voice_profile_id' => ['Kies eerst een gereed stemprofiel.']]);
        }

        return [
            'model' => $model,
            'voice' => $voice,
            'voice_design_revision' => $voice === null ? $designRevision : null,
            'speed' => (float) $settings['speed'],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(array $input, User $actor): array
    {
        $current = $this->settings();
        $next = $current;
        foreach (['enabled', 'model_id', 'voice_profile_id', 'speed', 'pre_generate_on_save'] as $key) {
            if (array_key_exists($key, $input)) {
                $next[$key] = $input[$key];
            }
        }
        if (array_key_exists('templates', $input)) {
            foreach ($this->templates->phases() as $phase) {
                if (array_key_exists($phase, $input['templates'])) {
                    $next['templates'][$phase] = $this->templates->validate($phase, $input['templates'][$phase]);
                }
            }
        }
        $next['speed'] = round((float) $next['speed'], 2);
        if ($next['speed'] < 0.85 || $next['speed'] > 1.15) {
            throw ValidationException::withMessages(['speed' => ['De spreeksnelheid moet tussen 0,85 en 1,15 liggen.']]);
        }
        if ($next['model_id'] !== null) {
            $this->catalog->model((string) $next['model_id']);
        }
        $voice = $next['voice_profile_id'] === null ? null : SpeechVoiceProfile::query()
            ->whereKey($next['voice_profile_id'])->where('status', 'ready')->first();
        if ($next['voice_profile_id'] !== null && $voice === null) {
            throw ValidationException::withMessages(['voice_profile_id' => ['Het gekozen stemprofiel is niet gereed.']]);
        }
        if ($next['model_id'] !== null && $voice !== null
            && ! $this->catalog->acceptsVoiceProfile((string) $next['model_id'])) {
            throw ValidationException::withMessages(['voice_profile_id' => ['Het gekozen model ondersteunt geen eigen stemprofiel.']]);
        }
        if ($next['enabled']) {
            if ($next['model_id'] === null || $this->installations->installedForCatalog((string) $next['model_id']) === null) {
                throw ValidationException::withMessages(['model_id' => ['Installeer het gekozen model voordat serverspraak wordt ingeschakeld.']]);
            }
            if ($voice === null && $this->catalog->builtInVoiceDesignRevision((string) $next['model_id']) === null) {
                throw ValidationException::withMessages(['voice_profile_id' => ['Dit model vereist een gereed stemprofiel.']]);
            }
        }

        DB::transaction(function () use ($next, $actor): void {
            $values = [
                'speech.enabled' => (bool) $next['enabled'],
                'speech.model_id' => $next['model_id'],
                'speech.voice_profile_id' => $next['voice_profile_id'],
                'speech.speed' => (float) $next['speed'],
                'speech.pre_generate_on_save' => (bool) $next['pre_generate_on_save'],
            ];
            foreach ($next['templates'] as $phase => $lines) {
                $values['speech.templates.'.$phase] = $this->templates->validate($phase, $lines);
            }
            foreach ($values as $key => $value) {
                SystemSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value, 'is_sensitive' => false, 'updated_by' => $actor->id],
                );
            }
            $this->audit->record('speech.settings_updated', 'speech_settings', $actor, [
                'enabled' => (bool) $next['enabled'],
                'model_id' => $next['model_id'],
                'voice_profile_id' => $next['voice_profile_id'],
                'speed' => (float) $next['speed'],
                'pre_generate_on_save' => (bool) $next['pre_generate_on_save'],
                'template_checksums' => collect($next['templates'])->map(
                    fn (array $lines, string $phase): string => $this->templates->checksum($phase, $lines),
                )->all(),
            ]);
        });

        return $this->status();
    }

    /** @return array<string, mixed> */
    public function voiceProfile(SpeechVoiceProfile $profile): array
    {
        $compatible = collect((array) config('dis.speech.models', []))
            ->filter(fn (mixed $model): bool => is_array($model) && (bool) ($model['capabilities']['voice_clone'] ?? false))
            ->keys()->values()->all();

        return [
            'id' => (string) $profile->id,
            'name' => (string) $profile->name,
            'locale' => (string) $profile->locale,
            'status' => in_array($profile->status, ['processing', 'ready', 'failed'], true) ? $profile->status : 'failed',
            'reference_duration_seconds' => round(((int) $profile->reference_duration_ms) / 1000, 2),
            'compatible_model_ids' => $compatible,
            'created_at' => $profile->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function cacheStatus(): array
    {
        $counter = DB::table('speech_cache_counters')->where('id', 1)->first();
        $active = SpeechCacheJob::query()->latest()->first();
        $diskBytes = (int) SpeechAudioAsset::query()->sum('byte_size');

        return [
            'segment_count' => SpeechCacheEntry::query()->where('category', 'segment')->where('status', 'ready')->count(),
            'composite_count' => SpeechCacheEntry::query()->where('category', 'composite')->where('status', 'ready')->count(),
            'hit_count' => (int) ($counter->hit_count ?? 0),
            'miss_count' => (int) ($counter->miss_count ?? 0),
            'disk_bytes' => $diskBytes,
            'quota_bytes' => (int) config('dis.speech.cache_quota_bytes', 5_368_709_120),
            'pending_count' => SpeechCacheEntry::query()->whereIn('status', ['queued', 'processing'])->count()
                + SpeechManifestBuild::query()->whereIn('status', ['queued', 'processing'])->count()
                + SpeechPreview::query()->whereIn('status', ['queued', 'processing'])->count(),
            'failed_count' => SpeechCacheEntry::query()->where('status', 'failed')->count()
                + SpeechManifestBuild::query()->where('status', 'failed')->count()
                + SpeechPreview::query()->where('status', 'failed')->count(),
            'last_pruned_at' => isset($counter->last_pruned_at) ? (string) $counter->last_pruned_at : null,
            'active_job' => $active === null ? null : [
                'id' => (string) $active->id,
                'scope' => (string) $active->scope,
                'status' => (string) $active->status,
                'progress_percent' => (int) $active->progress_percent,
                'error_code' => $active->error_code,
                'created_at' => $active->created_at?->toIso8601String(),
                'finished_at' => $active->finished_at?->toIso8601String(),
            ],
        ];
    }
}
