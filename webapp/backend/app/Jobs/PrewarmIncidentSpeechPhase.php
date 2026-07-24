<?php

namespace App\Jobs;

use App\Exceptions\SpeechEngineException;
use App\Models\Incident;
use App\Services\SpeechAudioPipeline;
use App\Services\SpeechPrewarmService;
use App\Services\SpeechSettingsService;
use App\Services\SpeechTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PrewarmIncidentSpeechPhase implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 64_800;

    public function __construct(
        public readonly string $incidentSpeechPreparationId,
        public readonly string $sourceFingerprint,
    ) {
        $this->onConnection('redis')->onQueue('speech');
    }

    public function handle(
        SpeechPrewarmService $prewarm,
        SpeechSettingsService $settings,
        SpeechTemplateService $templates,
        SpeechAudioPipeline $audio,
    ): void {
        $preparation = $prewarm->claim(
            $this->incidentSpeechPreparationId,
            $this->sourceFingerprint,
        );
        if ($preparation === null) {
            return;
        }

        $incident = $preparation->incident;
        if (! $incident instanceof Incident
            || in_array((string) $incident->status, ['resolved', 'cancelled'], true)
            || ! $prewarm->isCurrentSource($preparation, $incident)) {
            $prewarm->cancel($this->incidentSpeechPreparationId, $this->sourceFingerprint);

            return;
        }

        try {
            $phase = (string) $preparation->phase;
            if (! in_array($phase, [
                SpeechTemplateService::PHASE_AVAILABILITY,
                SpeechTemplateService::PHASE_ATTENDANCE,
            ], true)) {
                $prewarm->fail(
                    $this->incidentSpeechPreparationId,
                    $this->sourceFingerprint,
                    'speech_preparation_phase_invalid',
                );

                return;
            }

            $runtime = $settings->selectedRuntime();
            $lines = $templates->render(
                $phase,
                $templates->template($phase),
                $templates->contextForIncident($phase, $incident),
            );
            $assets = [];
            $lineCount = count($lines);
            foreach ($lines as $index => $line) {
                $assets[] = $audio->segment(
                    $line,
                    $runtime['model'],
                    $runtime['voice'],
                    $runtime['speed'],
                );
                $progress = 10 + (int) floor((($index + 1) / $lineCount) * 70);
                if (! $prewarm->progress(
                    $this->incidentSpeechPreparationId,
                    $this->sourceFingerprint,
                    $progress,
                )) {
                    return;
                }
            }

            if (! $prewarm->progress(
                $this->incidentSpeechPreparationId,
                $this->sourceFingerprint,
                90,
            )) {
                return;
            }
            $audio->composite(
                $assets,
                $phase,
                $runtime['voice'],
                $lines,
                $runtime['model'],
                $runtime['speed'],
            );

            $currentIncident = Incident::query()->find($incident->id);
            $currentPreparation = $preparation->refresh();
            if ($currentIncident === null
                || in_array((string) $currentIncident->status, ['resolved', 'cancelled'], true)
                || ! $prewarm->isCurrentSource($currentPreparation, $currentIncident)) {
                $prewarm->cancel($this->incidentSpeechPreparationId, $this->sourceFingerprint);

                return;
            }

            $prewarm->complete($this->incidentSpeechPreparationId, $this->sourceFingerprint);
        } catch (SpeechEngineException $exception) {
            $prewarm->fail(
                $this->incidentSpeechPreparationId,
                $this->sourceFingerprint,
                $exception->errorCode,
            );
        } catch (ValidationException) {
            $prewarm->fail(
                $this->incidentSpeechPreparationId,
                $this->sourceFingerprint,
                'speech_configuration_invalid',
            );
        } catch (Throwable) {
            $prewarm->fail(
                $this->incidentSpeechPreparationId,
                $this->sourceFingerprint,
                'incident_speech_preparation_failed',
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(SpeechPrewarmService::class)->fail(
            $this->incidentSpeechPreparationId,
            $this->sourceFingerprint,
            'speech_preparation_worker_failed',
        );
    }
}
