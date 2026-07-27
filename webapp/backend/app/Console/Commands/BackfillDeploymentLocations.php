<?php

namespace App\Console\Commands;

use App\Jobs\ResolveDeploymentLocation;
use App\Models\Deployment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BackfillDeploymentLocations extends Command
{
    protected $signature = 'dis:backfill-deployment-locations {--batch= : Maximum unresolved deployments to enqueue}';

    protected $description = 'Queue bounded province and country enrichment for deployments with coordinates';

    public function handle(): int
    {
        if (! (bool) config('dis.deployment_location.enabled', true)) {
            $this->info('Deployment location enrichment is disabled.');

            return self::SUCCESS;
        }

        $batchOption = $this->option('batch');
        if ($batchOption !== null
            && (! is_string($batchOption) || preg_match('/^[2-3]$/', $batchOption) !== 1)) {
            $this->error('The --batch option must be an integer from 2 through 3.');

            return self::INVALID;
        }

        $batch = $batchOption === null
            ? max(2, min(3, (int) config('dis.deployment_location.backfill_batch', 3)))
            : (int) $batchOption;

        // Keep one lane for the most recently created never-attempted deployment.
        // This lets the scheduler recover a new deployment promptly after a
        // transient queue outage without starving the oldest due backlog.
        $recentDeploymentId = $this->dueDeploymentsQuery()
            ->whereNull('location_enrichment_attempted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');
        $deploymentIds = collect();

        if ($recentDeploymentId !== null) {
            $deploymentIds->push((string) $recentDeploymentId);
        }

        $remaining = $batch - $deploymentIds->count();
        if ($remaining > 0) {
            $oldestDue = $this->dueDeploymentsQuery()
                ->when(
                    $recentDeploymentId !== null,
                    fn (Builder $query): Builder => $query->where('id', '!=', $recentDeploymentId),
                )
                ->orderByRaw('COALESCE(location_enrichment_attempted_at, created_at)')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($remaining)
                ->pluck('id');

            $deploymentIds = $deploymentIds->concat($oldestDue);
        }

        $queued = 0;
        foreach ($deploymentIds as $deploymentId) {
            try {
                ResolveDeploymentLocation::dispatch((string) $deploymentId);
                DB::table('deployments')->where('id', (string) $deploymentId)->update([
                    'location_enrichment_attempted_at' => now(),
                ]);
                $queued++;
            } catch (Throwable) {
                Log::warning('Deployment location enrichment could not be queued.');
            }
        }

        $this->info(sprintf('Queued location enrichment for %d deployment(s).', $queued));

        return self::SUCCESS;
    }

    /**
     * @return Builder<Deployment>
     */
    private function dueDeploymentsQuery(): Builder
    {
        return Deployment::query()
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
