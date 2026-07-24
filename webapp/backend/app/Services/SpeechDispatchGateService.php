<?php

namespace App\Services;

use App\Jobs\GenerateDispatchSpeechManifest;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechVoiceProfile;
use App\Models\SystemSetting;
use App\Repositories\SpeechModelInstallationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SpeechDispatchGateService
{
    public function __construct(
        private readonly SpeechTemplateService $templates,
        private readonly SpeechCacheKeyService $keys,
        private readonly SpeechModelInstallationRepository $installations,
        private readonly DispatchPushOutboxService $outbox,
        private readonly SpeechRuntimeActivityGate $runtime,
        private readonly TestAlertSpeechContentService $testAlertSpeech,
    ) {}

    /** @return array{delayed:bool,deadline:?Carbon,build_id:?string} */
    public function prepare(
        DispatchRequest $dispatch,
        Incident $incident,
        Carbon $queuedAt,
        ?array $testAlertLines = null,
    ): array {
        $this->runtime->preemptForAlarm($incident);
        if (! SystemSetting::boolean('speech.enabled', false)) {
            $this->markImmediate($dispatch, $queuedAt);

            return ['delayed' => false, 'deadline' => null, 'build_id' => null];
        }
        $modelId = SystemSetting::string('speech.model_id');
        $voiceId = SystemSetting::string('speech.voice_profile_id');
        $model = $modelId === null ? null : $this->installations->installedForCatalog($modelId);
        $voice = $voiceId === null ? null : SpeechVoiceProfile::query()->whereKey($voiceId)->where('status', 'ready')->first();
        $voiceDesignRevision = $modelId === null
            ? null
            : trim((string) config('dis.speech.models.'.$modelId.'.built_in_voice_design_revision'));
        $supportsVoiceDesign = $modelId !== null
            && config('dis.speech.models.'.$modelId.'.capabilities.voice_design') === true;
        $supportsVoiceProfile = $modelId !== null
            && config('dis.speech.models.'.$modelId.'.capabilities.voice_clone') === true;
        $audioRecipeRevision = trim((string) config('dis.speech.audio_recipe_revision'));
        if ($model === null || ($voiceId !== null && ($voice === null || ! $supportsVoiceProfile))
            || ($voiceId === null && ($voiceDesignRevision === '' || ! $supportsVoiceDesign))
            || $audioRecipeRevision === '') {
            $this->markImmediate($dispatch, $queuedAt);

            return ['delayed' => false, 'deadline' => null, 'build_id' => null];
        }

        $phase = $this->phaseFor($incident);
        $context = $this->templates->contextForIncident($phase, $incident);
        try {
            if ($phase === SpeechTemplateService::PHASE_TEST_ACK) {
                $lines = $testAlertLines === null
                    ? $this->testAlertSpeech->lines((string) $dispatch->message)
                    : $this->testAlertSpeech->validateLines($testAlertLines);
                $templateChecksum = $this->testAlertSpeech->checksum($lines);
            } else {
                $template = $this->templates->template($phase);
                $lines = $this->templates->render($phase, $template, $context);
                $templateChecksum = $this->templates->checksum($phase, $template);
            }
        } catch (ValidationException) {
            $this->markImmediate($dispatch, $queuedAt);

            return ['delayed' => false, 'deadline' => null, 'build_id' => null];
        }
        $deadline = $queuedAt->copy()->addSeconds((int) config('dis.speech.release_gate_seconds', 10));
        $build = SpeechManifestBuild::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'phase' => $phase,
            'locale' => 'nl-NL',
            'model_installation_id' => $model->id,
            'voice_profile_id' => $voice?->id,
            'voice_design_revision' => $voice === null ? $voiceDesignRevision : null,
            'audio_recipe_revision' => $audioRecipeRevision,
            'speed' => round((float) SystemSetting::value('speech.speed', 1.0), 2),
            'template_checksum' => $templateChecksum,
            'context_hmac' => $this->keys->key('dispatch-context', $context),
            'source_fingerprint_hmac' => $this->keys->key('dispatch-source', [
                'dispatch_id' => (string) $dispatch->id,
                'phase' => $phase,
                'incident_updated_at' => $incident->updated_at?->toIso8601String(),
                'template_checksum' => $templateChecksum,
                'model_installation_id' => (string) $model->id,
                'voice_profile_id' => $voice?->id,
                'voice_consent_version' => $voice?->consent_version,
                'voice_design_revision' => $voice === null ? $voiceDesignRevision : null,
                'audio_recipe_revision' => $audioRecipeRevision,
            ]),
            'rendered_lines' => $lines,
            'status' => 'queued',
            'progress_percent' => 0,
            'release_deadline' => $deadline,
            'expires_at' => now()->addDays((int) config('dis.speech.composite_retention_days', 7)),
        ]);
        $dispatch->forceFill([
            'send_status' => 'preparing_speech',
            'send_queued_at' => $queuedAt,
            'send_release_deadline' => $deadline,
            'send_released_at' => null,
        ])->save();

        return ['delayed' => true, 'deadline' => $deadline, 'build_id' => (string) $build->id];
    }

    public function queueAfterCommit(string $buildId): void
    {
        $dispatch = fn () => GenerateDispatchSpeechManifest::dispatch($buildId);
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }

    public function releaseReady(SpeechManifestBuild $build, SpeechManifest $manifest): void
    {
        if ($build->dispatch_request_id === null || $build->release_deadline === null
            || ! in_array($build->phase, [
                SpeechTemplateService::PHASE_ATTENDANCE,
                SpeechTemplateService::PHASE_TEST_ACK,
            ], true)
            || $manifest->phase !== $build->phase
            || (string) $manifest->dispatch_request_id !== (string) $build->dispatch_request_id) {
            return;
        }
        $dispatchMetadata = DispatchRequest::query()->select(['id', 'incident_id'])->find($build->dispatch_request_id);
        if ($dispatchMetadata === null) {
            return;
        }
        $released = DB::transaction(function () use ($build, $manifest, $dispatchMetadata): bool {
            $incident = Incident::query()->whereKey($dispatchMetadata->incident_id)->lockForUpdate()->first();
            $dispatch = DispatchRequest::query()->whereKey($dispatchMetadata->id)->lockForUpdate()->first();
            if ($incident === null || $dispatch === null || ! in_array($dispatch->status, ['sent', 'escalated'], true)
                || in_array($incident->status, ['resolved', 'cancelled'], true)
                || $build->phase !== $this->phaseFor($incident)
                || now()->greaterThanOrEqualTo($build->release_deadline)) {
                return false;
            }
            $rows = DispatchPushOutbox::query()
                ->where('dispatch_request_id', $dispatch->id)
                ->where('message_type', 'dispatch_request')
                ->whereNull('delivered_at')->whereNull('cancelled_at')->whereNull('queued_at')
                ->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                return false;
            }
            $attached = 0;
            foreach ($rows as $row) {
                $data = (array) $row->data;
                if (! $this->pushDataMatchesPhase($data, (string) $build->phase, (bool) $incident->is_test)) {
                    continue;
                }
                unset(
                    $data['speech_manifest_id'],
                    $data['speech_phase'],
                    $data['speech_manifest_url'],
                    $data['speech_manifest_version'],
                    $data['speech_locale'],
                );
                $data['speech_manifest_id'] = (string) $manifest->id;
                $data['speech_phase'] = (string) $manifest->phase;
                $row->forceFill([
                    'speech_manifest_id' => $manifest->id,
                    'release_reason' => 'speech_ready',
                    'available_at' => now(),
                    'data' => $data,
                ])->save();
                $attached++;
            }
            if ($attached === 0) {
                return false;
            }
            $dispatch->forceFill([
                'send_status' => 'queued_for_push',
                'send_released_at' => now(),
            ])->save();

            return true;
        });
        if ($released) {
            $this->outbox->flushPending(500, (string) $build->dispatch_request_id);
        }
    }

    private function markImmediate(DispatchRequest $dispatch, Carbon $queuedAt): void
    {
        $dispatch->forceFill([
            'send_status' => 'queued_for_push',
            'send_queued_at' => $queuedAt,
            'send_release_deadline' => null,
            'send_released_at' => $queuedAt,
        ])->save();
    }

    private function phaseFor(Incident $incident): string
    {
        return (bool) $incident->is_test
            ? SpeechTemplateService::PHASE_TEST_ACK
            : SpeechTemplateService::PHASE_ATTENDANCE;
    }

    /** @param array<string, mixed> $data */
    private function pushDataMatchesPhase(array $data, string $phase, bool $isTest): bool
    {
        return ($data['action_mode'] ?? null) === $phase
            && ($data['is_test'] ?? null) === ($isTest ? 'true' : 'false');
    }
}
