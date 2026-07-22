<?php

namespace App\Services;

use App\Jobs\GenerateSpeechPreview;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechPreview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SpeechPreviewService
{
    public function __construct(
        private readonly SpeechSettingsService $settings,
        private readonly SpeechTemplateService $templates,
        private readonly SpeechCacheKeyService $keys,
    ) {}

    public function create(string $phase, User $actor): SpeechPreview
    {
        $runtime = $this->settings->selectedRuntime();
        $template = $this->templates->template($phase);
        $lines = $this->templates->render($phase, $template, $this->templates->exampleContext($phase));
        $audioRecipeRevision = trim((string) config('dis.speech.audio_recipe_revision'));

        return DB::transaction(function () use ($phase, $actor, $runtime, $template, $lines, $audioRecipeRevision): SpeechPreview {
            $build = SpeechManifestBuild::query()->create([
                'phase' => $phase,
                'locale' => 'nl-NL',
                'model_installation_id' => $runtime['model']->id,
                'voice_profile_id' => $runtime['voice']?->id,
                'voice_design_revision' => $runtime['voice_design_revision'],
                'audio_recipe_revision' => $audioRecipeRevision,
                'speed' => $runtime['speed'],
                'template_checksum' => $this->templates->checksum($phase, $template),
                'context_hmac' => $this->keys->key('preview-context', ['phase' => $phase, 'lines' => $lines]),
                'source_fingerprint_hmac' => $this->keys->key('preview-build', [
                    'request' => (string) Str::ulid(),
                    'phase' => $phase,
                    'audio_recipe_revision' => $audioRecipeRevision,
                ]),
                'rendered_lines' => $lines,
                'status' => 'queued',
                'progress_percent' => 0,
                'expires_at' => now()->addHours((int) config('dis.speech.preview_retention_hours', 24)),
            ]);
            $preview = SpeechPreview::query()->create([
                'requested_by' => $actor->id,
                'phase' => $phase,
                'status' => 'queued',
                'progress_percent' => 0,
                'rendered_lines' => $lines,
                'speech_manifest_build_id' => $build->id,
                'expires_at' => $build->expires_at,
            ]);
            DB::afterCommit(fn () => GenerateSpeechPreview::dispatch((string) $preview->id));

            return $preview;
        });
    }

    /** @return array<string, mixed> */
    public function payload(SpeechPreview $preview): array
    {
        $expired = $preview->expires_at?->isPast() === true;
        $status = $expired && $preview->status !== 'ready' ? 'failed' : (string) $preview->status;

        return [
            'id' => (string) $preview->id,
            'phase' => (string) $preview->phase,
            'status' => in_array($status, ['queued', 'processing', 'ready', 'failed'], true) ? $status : 'failed',
            'progress_percent' => (int) $preview->progress_percent,
            'rendered_lines' => array_values((array) $preview->rendered_lines),
            'error_code' => $expired ? 'preview_expired' : $preview->error_code,
            'created_at' => $preview->created_at?->toIso8601String(),
            'expires_at' => $preview->expires_at?->toIso8601String(),
        ];
    }
}
