<?php

namespace Tests\Feature;

use App\Jobs\GenerateIncidentReport;
use App\Jobs\ResolveIncidentLocation;
use App\Services\DeploymentLocationEnrichmentService;
use App\Services\DeploymentReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeploymentQueueCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_report_job_deserializes_and_delegates_safely(): void
    {
        $deploymentId = (string) Str::ulid();
        $serialized = serialize(new GenerateIncidentReport($deploymentId, true));
        $job = unserialize($serialized, [
            'allowed_classes' => [GenerateIncidentReport::class],
        ]);

        $this->assertInstanceOf(GenerateIncidentReport::class, $job);
        $this->assertSame($deploymentId, $job->incidentId);
        $this->assertTrue($job->refreshExisting);

        $job->handle(app(DeploymentReportService::class));

        $this->assertDatabaseMissing('deployments', ['id' => $deploymentId]);
    }

    public function test_legacy_location_job_deserializes_and_delegates_safely(): void
    {
        config()->set('dis.deployment_location.enabled', true);
        $deploymentId = (string) Str::ulid();
        $serialized = serialize(new ResolveIncidentLocation($deploymentId));
        $job = unserialize($serialized, [
            'allowed_classes' => [ResolveIncidentLocation::class],
        ]);

        $this->assertInstanceOf(ResolveIncidentLocation::class, $job);
        $this->assertSame($deploymentId, $job->incidentId);
        $this->assertSame($deploymentId, $job->uniqueId());
        $this->assertSame('incident-enrichment', $job->queue);

        $job->handle(app(DeploymentLocationEnrichmentService::class));

        $this->assertDatabaseMissing('deployments', ['id' => $deploymentId]);
    }
}
