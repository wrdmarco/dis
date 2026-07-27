<?php

namespace App\Jobs;

use App\Services\DeploymentReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Compatibility envelope for jobs serialized before the deployment-domain cutover.
 *
 * New work must dispatch GenerateDeploymentReport.
 */
final class GenerateIncidentReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly string $incidentId,
        public readonly bool $refreshExisting = false,
    ) {}

    public function handle(DeploymentReportService $reports): void
    {
        (new GenerateDeploymentReport($this->incidentId, $this->refreshExisting))->handle($reports);
    }
}
