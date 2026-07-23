<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Services\SpeechAudioPipeline;
use App\Services\SpeechSettingsService;
use App\Services\SpeechTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PrewarmIncidentSpeech implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 64_800;

    public function __construct(public readonly string $incidentId)
    {
        $this->onConnection('redis')->onQueue('speech');
    }

    public function handle(
        SpeechSettingsService $settings,
        SpeechTemplateService $templates,
        SpeechAudioPipeline $audio,
    ): void {
        $incident = Incident::query()->find($this->incidentId);
        if ($incident === null || in_array($incident->status, ['resolved', 'cancelled'], true)) {
            return;
        }
        $runtime = $settings->selectedRuntime();
        foreach ([SpeechTemplateService::PHASE_AVAILABILITY, SpeechTemplateService::PHASE_ATTENDANCE] as $phase) {
            $lines = $templates->render(
                $phase,
                $templates->template($phase),
                $templates->contextForIncident($phase, $incident),
            );
            $assets = [];
            foreach ($lines as $line) {
                $assets[] = $audio->segment($line, $runtime['model'], $runtime['voice'], $runtime['speed']);
            }
            $audio->composite(
                $assets,
                $phase,
                $runtime['voice'],
                $lines,
                $runtime['model'],
                $runtime['speed'],
            );
        }
    }
}
