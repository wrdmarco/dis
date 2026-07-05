<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\PilotIncidentReport;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PilotIncidentReportService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly PilotIncidentReportFormService $formService,
        private readonly IncidentReportService $incidentReportService,
    ) {}

    public function ensureForOnScene(User $user, User $actor): void
    {
        $this->acceptedIncidentsForUser($user, ['active', 'dispatching', 'in_progress'])->each(function (Incident $incident) use ($user, $actor): void {
            $report = $this->ensureReport($incident, $user);

            $this->auditService->record('pilot_incident_report.prepared', $report, $actor, [
                'incident_id' => $incident->id,
                'user_id' => $user->id,
            ]);
        });
    }

    public function show(Incident $incident, User $user): PilotIncidentReport
    {
        $this->assertCanReport($incident, $user);

        $existing = $this->existingReport($incident, $user);
        if ($existing !== null) {
            return $existing;
        }

        $this->assertOnScene($user);

        return $this->ensureReport($incident, $user)->refresh();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function submit(Incident $incident, User $user, array $data): PilotIncidentReport
    {
        $this->assertCanReport($incident, $user);
        $this->assertCanSubmit($incident, $user);

        $report = DB::transaction(function () use ($incident, $user, $data): PilotIncidentReport {
            $report = $this->ensureReport($incident, $user);
            $report->fill([
                'summary' => $data['summary'] ?? null,
                'observations' => $data['observations'] ?? null,
                'actions_taken' => $data['actions_taken'] ?? null,
                'result' => $data['result'] ?? null,
                'issues' => $data['issues'] ?? null,
                'equipment_used' => $data['equipment_used'] ?? null,
                'flight_minutes' => $data['flight_minutes'] ?? null,
                'custom_fields' => $this->formService->normalizeCustomValues($data),
                'status' => 'submitted',
                'submitted_at' => now(),
            ])->save();

            $this->auditService->record('pilot_incident_report.submitted', $report, $user, [
                'incident_id' => $incident->id,
                'user_id' => $user->id,
            ]);

            return $report->refresh();
        });

        $this->incidentReportService->refreshStored($incident->refresh(), preserveExistingMaps: true);

        return $report;
    }

    private function ensureReport(Incident $incident, User $user): PilotIncidentReport
    {
        return PilotIncidentReport::query()->firstOrCreate(
            [
                'incident_id' => $incident->id,
                'user_id' => $user->id,
            ],
            [
                'user_name' => $user->name,
                'user_email' => $user->email,
                'status' => 'draft',
                'prepared_at' => now(),
            ],
        );
    }

    private function existingReport(Incident $incident, User $user): ?PilotIncidentReport
    {
        return PilotIncidentReport::query()
            ->where('incident_id', $incident->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function assertCanReport(Incident $incident, User $user): void
    {
        if (! $this->acceptedIncidentsForUser($user, ['active', 'dispatching', 'in_progress', 'resolved', 'cancelled'])->contains('id', $incident->id)) {
            throw ValidationException::withMessages([
                'incident' => ['Alleen geaccepteerde opkomers kunnen een inzetverslag voor dit incident invullen.'],
            ]);
        }
    }

    private function assertOnScene(User $user): void
    {
        $latestStatus = $user->statuses()
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latestStatus?->status !== 'on_scene') {
            throw ValidationException::withMessages([
                'status' => ['Het inzetverslag kan pas worden ingevuld nadat je op locatie bent.'],
            ]);
        }
    }

    private function assertCanSubmit(Incident $incident, User $user): void
    {
        if (in_array($incident->status, ['resolved', 'cancelled'], true)) {
            return;
        }

        $this->assertOnScene($user);
    }

    /**
     * @return Collection<int, Incident>
     */
    private function acceptedIncidentsForUser(User $user, array $statuses): Collection
    {
        return Incident::query()
            ->whereIn('status', $statuses)
            ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                ->whereIn('status', ['sent', 'escalated'])
                ->whereHas('recipients', fn ($recipients) => $recipients
                    ->where('user_id', $user->id)
                    ->where('response_status', 'accepted')))
            ->get();
    }
}
