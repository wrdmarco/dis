<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DeploymentLocationEnrichmentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ResolveDeploymentLocation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 25;

    public int $uniqueFor = 21600;

    public function __construct(public readonly string $deploymentId)
    {
        $this->onQueue('deployment-enrichment');
    }

    public function uniqueId(): string
    {
        return $this->deploymentId;
    }

    public function handle(DeploymentLocationEnrichmentService $enrichmentService): void
    {
        if (! (bool) config('dis.deployment_location.enabled', true)) {
            return;
        }

        $deployment = Deployment::query()->find($this->deploymentId);
        if ($deployment === null
            || $deployment->is_test
            || ($deployment->province_resolved_at !== null && $deployment->country_resolved_at !== null)) {
            return;
        }

        DB::table('deployments')->where('id', $this->deploymentId)->update([
            'location_enrichment_attempted_at' => now(),
        ]);
        try {
            $resolved = $enrichmentService->resolve($deployment);
        } catch (RuntimeException) {
            Log::warning('Deployment location enrichment provider is temporarily unavailable.');

            return;
        }

        if ($resolved) {
            return;
        }

        DB::table('deployments')
            ->where('id', $this->deploymentId)
            ->where(function ($query): void {
                $query->whereNull('province_resolved_at')
                    ->orWhereNull('country_resolved_at');
            })
            ->update(['location_enrichment_attempted_at' => null]);
    }
}
