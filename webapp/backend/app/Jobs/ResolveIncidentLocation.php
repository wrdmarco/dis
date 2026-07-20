<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Services\IncidentLocationEnrichmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ResolveIncidentLocation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 25;

    public int $uniqueFor = 21600;

    public function __construct(public readonly string $incidentId)
    {
        $this->onQueue('incident-enrichment');
    }

    public function uniqueId(): string
    {
        return $this->incidentId;
    }

    public function handle(IncidentLocationEnrichmentService $enrichmentService): void
    {
        if (! (bool) config('dis.incident_location.enabled', true)) {
            return;
        }

        $incident = Incident::query()->find($this->incidentId);
        if ($incident === null
            || $incident->is_test
            || ($incident->province_resolved_at !== null && $incident->country_resolved_at !== null)) {
            return;
        }

        DB::table('incidents')->where('id', $this->incidentId)->update([
            'location_enrichment_attempted_at' => now(),
        ]);
        try {
            $resolved = $enrichmentService->resolve($incident);
        } catch (RuntimeException) {
            Log::warning('Incident location enrichment provider is temporarily unavailable.');

            return;
        }

        if ($resolved) {
            return;
        }

        DB::table('incidents')
            ->where('id', $this->incidentId)
            ->where(function ($query): void {
                $query->whereNull('province_resolved_at')
                    ->orWhereNull('country_resolved_at');
            })
            ->update(['location_enrichment_attempted_at' => null]);
    }
}
