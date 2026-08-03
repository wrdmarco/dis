<?php

namespace App\Jobs;

use App\Services\DeploymentLocationEnrichmentService;
use App\Services\GeocodingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Compatibility envelope for jobs serialized before the deployment-domain cutover.
 *
 * The canonical worker drains incident-enrichment only until legacy work is gone.
 * New work must dispatch ResolveDeploymentLocation.
 */
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

    public function handle(
        DeploymentLocationEnrichmentService $enrichmentService,
        GeocodingService $geocodingService,
    ): void {
        (new ResolveDeploymentLocation($this->incidentId))->handle($enrichmentService, $geocodingService);
    }
}
