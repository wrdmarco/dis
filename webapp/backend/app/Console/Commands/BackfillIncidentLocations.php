<?php

namespace App\Console\Commands;

use App\Jobs\ResolveIncidentLocation;
use App\Models\Incident;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BackfillIncidentLocations extends Command
{
    protected $signature = 'dis:backfill-incident-locations {--batch= : Maximum unresolved incidents to enqueue}';

    protected $description = 'Queue bounded province and country enrichment for incidents with coordinates';

    public function handle(): int
    {
        if (! (bool) config('dis.incident_location.enabled', true)) {
            $this->info('Incident location enrichment is disabled.');

            return self::SUCCESS;
        }

        $batchOption = $this->option('batch');
        if ($batchOption !== null
            && (! is_string($batchOption) || preg_match('/^[2-3]$/', $batchOption) !== 1)) {
            $this->error('The --batch option must be an integer from 2 through 3.');

            return self::INVALID;
        }

        $batch = $batchOption === null
            ? max(2, min(3, (int) config('dis.incident_location.backfill_batch', 3)))
            : (int) $batchOption;

        // Keep one lane for the most recently created never-attempted incident.
        // This lets the scheduler recover a new incident promptly after a
        // transient queue outage without starving the oldest due backlog.
        $recentIncidentId = $this->dueIncidentsQuery()
            ->whereNull('location_enrichment_attempted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');
        $incidentIds = collect();

        if ($recentIncidentId !== null) {
            $incidentIds->push((string) $recentIncidentId);
        }

        $remaining = $batch - $incidentIds->count();
        if ($remaining > 0) {
            $oldestDue = $this->dueIncidentsQuery()
                ->when(
                    $recentIncidentId !== null,
                    fn (Builder $query): Builder => $query->where('id', '!=', $recentIncidentId),
                )
                ->orderByRaw('COALESCE(location_enrichment_attempted_at, created_at)')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($remaining)
                ->pluck('id');

            $incidentIds = $incidentIds->concat($oldestDue);
        }

        $queued = 0;
        foreach ($incidentIds as $incidentId) {
            try {
                ResolveIncidentLocation::dispatch((string) $incidentId);
                DB::table('incidents')->where('id', (string) $incidentId)->update([
                    'location_enrichment_attempted_at' => now(),
                ]);
                $queued++;
            } catch (Throwable) {
                Log::warning('Incident location enrichment could not be queued.');
            }
        }

        $this->info(sprintf('Queued location enrichment for %d incident(s).', $queued));

        return self::SUCCESS;
    }

    /**
     * @return Builder<Incident>
     */
    private function dueIncidentsQuery(): Builder
    {
        return Incident::query()
            ->where('is_test', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where(function (Builder $query): void {
                $query->whereNull('province_resolved_at')
                    ->orWhereNull('country_resolved_at');
            })
            ->where(function (Builder $query): void {
                $query->whereNull('location_enrichment_attempted_at')
                    ->orWhere('location_enrichment_attempted_at', '<=', now()->subHours(6));
            });
    }
}
