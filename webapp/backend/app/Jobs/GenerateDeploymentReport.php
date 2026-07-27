<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DeploymentReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class GenerateDeploymentReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly string $deploymentId,
        public readonly bool $refreshExisting = false,
    ) {}

    public function handle(DeploymentReportService $reports): void
    {
        $deployment = Deployment::query()->find($this->deploymentId);
        if ($deployment === null) {
            return;
        }

        try {
            if ($this->refreshExisting) {
                $reports->refreshStored($deployment, preserveExistingMaps: true);

                return;
            }

            $reports->ensureStored($deployment);
        } catch (Throwable $exception) {
            report($exception);
            $deployment->forceFill([
                'report_generation_error' => 'Report generation failed. See secured server logs.',
            ])->save();

            throw $exception;
        }
    }
}
