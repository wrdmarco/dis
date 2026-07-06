<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Services\IncidentReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class GenerateIncidentReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly string $incidentId,
        public readonly bool $refreshExisting = false,
    ) {}

    public function handle(IncidentReportService $reports): void
    {
        $incident = Incident::query()->find($this->incidentId);
        if ($incident === null) {
            return;
        }

        try {
            if ($this->refreshExisting) {
                $reports->refreshStored($incident, preserveExistingMaps: true);

                return;
            }

            $reports->ensureStored($incident);
        } catch (Throwable $exception) {
            report($exception);
            $incident->forceFill([
                'report_generation_error' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            throw $exception;
        }
    }
}
