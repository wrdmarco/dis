<?php

namespace App\Services;

use App\Events\IncidentChanged;
use App\Jobs\PrewarmIncidentSpeechPhase;
use App\Models\Incident;
use App\Models\IncidentSpeechPreparation;
use App\Models\SpeechVoiceProfile;
use App\Models\SystemSetting;
use App\Support\ApiDateTime;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SpeechPrewarmService
{
    /** @var list<string> */
    private const PHASES = [
        SpeechTemplateService::PHASE_AVAILABILITY,
        SpeechTemplateService::PHASE_ATTENDANCE,
    ];

    public function __construct(
        private readonly SpeechTemplateService $templates,
        private readonly SpeechCacheKeyService $cacheKeys,
    ) {}

    public function queueAfterCommit(string $incidentId): void
    {
        $incident = Incident::query()->find($incidentId);
        if ($incident === null) {
            return;
        }

        $status = $this->scheduledStatus($incident);
        $queued = [];
        foreach (self::PHASES as $phase) {
            $phaseStatus = $status;
            $errorCode = null;
            try {
                $sourceFingerprint = $this->sourceFingerprint($incident, $phase);
            } catch (Throwable $exception) {
                report($exception);
                $sourceFingerprint = $this->unavailableSourceFingerprint(
                    $incident,
                    $phase,
                    $status,
                );
                if ($status === IncidentSpeechPreparation::STATUS_QUEUED) {
                    $phaseStatus = IncidentSpeechPreparation::STATUS_FAILED;
                    $errorCode = 'speech_configuration_invalid';
                }
            }
            $preparation = IncidentSpeechPreparation::query()->updateOrCreate(
                [
                    'incident_id' => $incident->id,
                    'phase' => $phase,
                ],
                [
                    'source_fingerprint_hmac' => $sourceFingerprint,
                    'status' => $phaseStatus,
                    'progress_percent' => 0,
                    'error_code' => $errorCode,
                ],
            );

            if ($phaseStatus === IncidentSpeechPreparation::STATUS_QUEUED) {
                $queued[] = [
                    'id' => (string) $preparation->id,
                    'source_fingerprint_hmac' => $sourceFingerprint,
                ];
            }
        }

        $afterCommit = function () use ($incidentId, $queued): void {
            $this->broadcastIncidentChange($incidentId);
            foreach ($queued as $preparation) {
                PrewarmIncidentSpeechPhase::dispatch(
                    $preparation['id'],
                    $preparation['source_fingerprint_hmac'],
                );
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($afterCommit);
        } else {
            $afterCommit();
        }
    }

    public function claim(string $preparationId, string $sourceFingerprint): ?IncidentSpeechPreparation
    {
        $updated = IncidentSpeechPreparation::query()
            ->whereKey($preparationId)
            ->where('source_fingerprint_hmac', $sourceFingerprint)
            ->where('status', IncidentSpeechPreparation::STATUS_QUEUED)
            ->update([
                'status' => IncidentSpeechPreparation::STATUS_PROCESSING,
                'progress_percent' => 5,
                'error_code' => null,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return null;
        }

        $preparation = IncidentSpeechPreparation::query()
            ->with('incident')
            ->whereKey($preparationId)
            ->where('source_fingerprint_hmac', $sourceFingerprint)
            ->where('status', IncidentSpeechPreparation::STATUS_PROCESSING)
            ->first();
        if ($preparation === null) {
            return null;
        }

        $this->broadcastIncidentChange((string) $preparation->incident_id);

        return $preparation;
    }

    public function progress(
        string $preparationId,
        string $sourceFingerprint,
        int $progressPercent,
    ): bool {
        return $this->transition(
            $preparationId,
            $sourceFingerprint,
            [IncidentSpeechPreparation::STATUS_PROCESSING],
            [
                'progress_percent' => max(5, min(99, $progressPercent)),
                'error_code' => null,
            ],
        );
    }

    public function complete(string $preparationId, string $sourceFingerprint): bool
    {
        return $this->transition(
            $preparationId,
            $sourceFingerprint,
            [IncidentSpeechPreparation::STATUS_PROCESSING],
            [
                'status' => IncidentSpeechPreparation::STATUS_READY,
                'progress_percent' => 100,
                'error_code' => null,
            ],
        );
    }

    public function fail(
        string $preparationId,
        string $sourceFingerprint,
        string $errorCode,
    ): bool {
        return $this->transition(
            $preparationId,
            $sourceFingerprint,
            [
                IncidentSpeechPreparation::STATUS_QUEUED,
                IncidentSpeechPreparation::STATUS_PROCESSING,
            ],
            [
                'status' => IncidentSpeechPreparation::STATUS_FAILED,
                'error_code' => $this->safeErrorCode($errorCode),
            ],
        );
    }

    public function cancel(string $preparationId, string $sourceFingerprint): bool
    {
        return $this->transition(
            $preparationId,
            $sourceFingerprint,
            [
                IncidentSpeechPreparation::STATUS_QUEUED,
                IncidentSpeechPreparation::STATUS_PROCESSING,
            ],
            [
                'status' => IncidentSpeechPreparation::STATUS_CANCELLED,
                'error_code' => null,
            ],
        );
    }

    public function isCurrentSource(
        IncidentSpeechPreparation $preparation,
        Incident $incident,
    ): bool {
        if ((string) $preparation->incident_id !== (string) $incident->id
            || ! in_array((string) $preparation->phase, self::PHASES, true)) {
            return false;
        }

        try {
            return hash_equals(
                (string) $preparation->source_fingerprint_hmac,
                $this->sourceFingerprint($incident, (string) $preparation->phase),
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{
     *     availability:array{phase:string,status:string,progress_percent:int,error_code:?string,updated_at:?string},
     *     attendance:array{phase:string,status:string,progress_percent:int,error_code:?string,updated_at:?string}
     * }
     */
    public function payload(Incident $incident): array
    {
        $preparations = IncidentSpeechPreparation::query()
            ->where('incident_id', $incident->id)
            ->whereIn('phase', self::PHASES)
            ->get()
            ->keyBy('phase');
        $defaultStatus = $this->defaultPayloadStatus($incident);
        $payload = [];

        foreach (self::PHASES as $phase) {
            /** @var IncidentSpeechPreparation|null $preparation */
            $preparation = $preparations->get($phase);
            if ($preparation === null) {
                $payload[$phase] = [
                    'phase' => $phase,
                    'status' => $defaultStatus,
                    'progress_percent' => 0,
                    'error_code' => null,
                    'updated_at' => null,
                ];

                continue;
            }

            $status = in_array((string) $preparation->status, IncidentSpeechPreparation::STATUSES, true)
                ? (string) $preparation->status
                : IncidentSpeechPreparation::STATUS_FAILED;
            $progress = max(0, min(100, (int) $preparation->progress_percent));
            if ($status === IncidentSpeechPreparation::STATUS_READY) {
                $progress = 100;
            }

            $payload[$phase] = [
                'phase' => $phase,
                'status' => $status,
                'progress_percent' => $progress,
                'error_code' => $status === IncidentSpeechPreparation::STATUS_FAILED
                    ? $this->safeErrorCode(
                        (string) $preparation->error_code,
                        'speech_preparation_state_invalid',
                    )
                    : null,
                'updated_at' => ApiDateTime::dateTime($preparation->updated_at),
            ];
        }

        /** @var array{
         *     availability:array{phase:string,status:string,progress_percent:int,error_code:?string,updated_at:?string},
         *     attendance:array{phase:string,status:string,progress_percent:int,error_code:?string,updated_at:?string}
         * } $payload
         */
        return $payload;
    }

    private function scheduledStatus(Incident $incident): string
    {
        if ($this->isTerminal($incident)) {
            return IncidentSpeechPreparation::STATUS_CANCELLED;
        }
        if (! SystemSetting::boolean('speech.enabled', false)) {
            return IncidentSpeechPreparation::STATUS_DISABLED;
        }
        if (! SystemSetting::boolean('speech.pre_generate_on_save', true)) {
            return IncidentSpeechPreparation::STATUS_NOT_SCHEDULED;
        }

        return IncidentSpeechPreparation::STATUS_QUEUED;
    }

    private function defaultPayloadStatus(Incident $incident): string
    {
        if ($this->isTerminal($incident)) {
            return IncidentSpeechPreparation::STATUS_CANCELLED;
        }
        if (! SystemSetting::boolean('speech.enabled', false)) {
            return IncidentSpeechPreparation::STATUS_DISABLED;
        }

        return IncidentSpeechPreparation::STATUS_NOT_SCHEDULED;
    }

    private function isTerminal(Incident $incident): bool
    {
        return in_array((string) $incident->status, ['resolved', 'cancelled'], true);
    }

    private function sourceFingerprint(Incident $incident, string $phase): string
    {
        $template = $this->templates->template($phase);
        $renderedLines = $this->templates->render(
            $phase,
            $template,
            $this->templates->contextForIncident($phase, $incident),
        );
        $modelId = SystemSetting::string('speech.model_id');
        $voiceProfileId = SystemSetting::string('speech.voice_profile_id');
        $voice = $voiceProfileId === null
            ? null
            : SpeechVoiceProfile::query()
                ->select(['id', 'sample_sha256', 'consent_version', 'status'])
                ->find($voiceProfileId);
        $speed = round(max(0.85, min(1.15, (float) SystemSetting::value('speech.speed', 1.0))), 2);

        return $this->cacheKeys->key('incident-speech-preparation-source', [
            'incident_id' => (string) $incident->id,
            'incident_status' => (string) $incident->status,
            'incident_updated_at' => $incident->updated_at?->format('Y-m-d\TH:i:s.uP'),
            'phase' => $phase,
            'rendered_lines' => $renderedLines,
            'template_checksum' => $this->templates->checksum($phase, $template),
            'locale' => (string) config('dis.speech.locale', 'nl-NL'),
            'model_id' => $modelId,
            'model_revision' => $modelId === null
                ? null
                : config('dis.speech.models.'.$modelId.'.revision'),
            'model_weights_sha256' => $modelId === null
                ? null
                : config('dis.speech.models.'.$modelId.'.weights_sha256'),
            'voice_profile_id' => $voiceProfileId,
            'voice_sample_sha256' => $voice?->sample_sha256,
            'voice_consent_version' => $voice?->consent_version,
            'voice_status' => $voice?->status,
            'speed' => number_format($speed, 2, '.', ''),
            'audio_recipe_revision' => (string) config('dis.speech.audio_recipe_revision'),
            'engine_protocol' => (int) config('dis.speech.protocol_version', 2),
        ]);
    }

    private function unavailableSourceFingerprint(
        Incident $incident,
        string $phase,
        string $status,
    ): string {
        return $this->cacheKeys->key('incident-speech-preparation-source-unavailable', [
            'incident_id' => (string) $incident->id,
            'incident_updated_at' => $incident->updated_at?->format('Y-m-d\TH:i:s.uP'),
            'phase' => $phase,
            'status' => $status,
            'audio_recipe_revision' => (string) config('dis.speech.audio_recipe_revision'),
        ]);
    }

    /**
     * @param  list<string>  $fromStatuses
     * @param  array<string, mixed>  $values
     */
    private function transition(
        string $preparationId,
        string $sourceFingerprint,
        array $fromStatuses,
        array $values,
    ): bool {
        $incidentId = IncidentSpeechPreparation::query()
            ->whereKey($preparationId)
            ->where('source_fingerprint_hmac', $sourceFingerprint)
            ->whereIn('status', $fromStatuses)
            ->value('incident_id');
        if (! is_string($incidentId) || $incidentId === '') {
            return false;
        }

        $updated = IncidentSpeechPreparation::query()
            ->whereKey($preparationId)
            ->where('source_fingerprint_hmac', $sourceFingerprint)
            ->whereIn('status', $fromStatuses)
            ->update($values + ['updated_at' => now()]);
        if ($updated !== 1) {
            return false;
        }

        $this->broadcastIncidentChange($incidentId);

        return true;
    }

    private function safeErrorCode(
        string $errorCode,
        string $fallback = 'incident_speech_preparation_failed',
    ): string {
        $errorCode = strtolower(trim($errorCode));

        return preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode) === 1
            ? $errorCode
            : $fallback;
    }

    private function broadcastIncidentChange(string $incidentId): void
    {
        try {
            $incident = Incident::query()->find($incidentId);
            if ($incident !== null) {
                IncidentChanged::dispatch($incident, 'speech_preparation_changed');
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
