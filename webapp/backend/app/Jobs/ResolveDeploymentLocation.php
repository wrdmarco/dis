<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DeploymentLocationEnrichmentService;
use App\Services\GeocodingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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

    public function handle(
        DeploymentLocationEnrichmentService $enrichmentService,
        GeocodingService $geocodingService,
    ): void {
        if (! (bool) config('dis.deployment_location.enabled', true)) {
            return;
        }

        $deployment = Deployment::query()->find($this->deploymentId);
        if ($deployment === null || $deployment->is_test) {
            return;
        }

        $coordinatesIncomplete = $deployment->latitude === null || $deployment->longitude === null;
        if (! $coordinatesIncomplete
            && $deployment->province_resolved_at !== null
            && $deployment->country_resolved_at !== null) {
            return;
        }

        if ($coordinatesIncomplete) {
            $sourceLocationLabel = $deployment->location_label;
            $locationLabel = trim((string) $sourceLocationLabel);
            if ($locationLabel === '') {
                return;
            }

            $attemptedAt = now()->startOfSecond();
            $attemptStarted = DB::table('deployments')
                ->where('id', $this->deploymentId)
                ->whereNull('deleted_at')
                ->where('location_label', $sourceLocationLabel)
                ->where(function ($query): void {
                    $query->whereNull('latitude')
                        ->orWhereNull('longitude');
                })
                ->update(['location_enrichment_attempted_at' => $attemptedAt]);
            if ($attemptStarted === 0) {
                return;
            }

            try {
                $coordinates = $geocodingService->coordinatesFor($locationLabel);
            } catch (Throwable) {
                Log::warning('Deployment location geocoding provider is temporarily unavailable.');

                return;
            }

            if ($coordinates === null) {
                return;
            }

            $updated = DB::table('deployments')
                ->where('id', $this->deploymentId)
                ->whereNull('deleted_at')
                ->where('location_label', $sourceLocationLabel)
                ->where('location_enrichment_attempted_at', $attemptedAt)
                ->where(function ($query): void {
                    $query->whereNull('latitude')
                        ->orWhereNull('longitude');
                })
                ->update([
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'],
                    'province_code' => null,
                    'province_name' => null,
                    'province_source' => null,
                    'province_resolved_at' => null,
                    'country_code' => null,
                    'country_name' => null,
                    'country_source' => null,
                    'country_resolved_at' => null,
                    'location_enrichment_attempted_at' => null,
                    'drone_flight_context' => null,
                ]);

            if ($updated === 0) {
                DB::table('deployments')
                    ->where('id', $this->deploymentId)
                    ->whereNull('deleted_at')
                    ->where('location_enrichment_attempted_at', $attemptedAt)
                    ->where(function ($query): void {
                        $query->whereNull('latitude')
                            ->orWhereNull('longitude');
                    })
                    ->update(['location_enrichment_attempted_at' => null]);
            }

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
