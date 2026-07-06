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

    public function showForActor(Incident $incident, User $user, User $actor): PilotIncidentReport
    {
        $this->assertCanReport($incident, $user);

        $report = $this->ensureReport($incident, $user)->refresh();
        $this->auditService->record('pilot_incident_report.opened_by_admin', $report, $actor, [
            'incident_id' => $incident->id,
            'user_id' => $user->id,
        ]);

        return $report;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function submit(Incident $incident, User $user, array $data): PilotIncidentReport
    {
        $this->assertCanReport($incident, $user);
        $this->assertCanSubmit($incident, $user);

        return $this->storeSubmission($incident, $user, $user, $data, 'pilot_incident_report.submitted');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function submitForActor(Incident $incident, User $user, User $actor, array $data): PilotIncidentReport
    {
        $this->assertCanReport($incident, $user);

        return $this->storeSubmission($incident, $user, $actor, $data, 'pilot_incident_report.submitted_by_admin');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function storeSubmission(Incident $incident, User $user, User $actor, array $data, string $auditAction): PilotIncidentReport
    {
        $report = DB::transaction(function () use ($incident, $user, $actor, $data, $auditAction): PilotIncidentReport {
            $report = $this->ensureReport($incident, $user);
            $this->assertReportCanBeEdited($report);

            $submittedAt = $report->submitted_at ?? now();
            $customFields = $this->formService->normalizeCustomValues($data);
            $standardValues = $this->standardValuesFromCustomFields($customFields);
            $report->fill([
                'summary' => $data['summary'] ?? $standardValues['summary'],
                'observations' => $data['observations'] ?? $standardValues['observations'],
                'actions_taken' => $data['actions_taken'] ?? $standardValues['actions_taken'],
                'result' => $data['result'] ?? $standardValues['result'],
                'issues' => $data['issues'] ?? $standardValues['issues'],
                'equipment_used' => $data['equipment_used'] ?? $standardValues['equipment_used'],
                'flight_minutes' => $data['flight_minutes'] ?? $standardValues['flight_minutes'],
                'custom_fields' => $customFields,
                'status' => 'submitted',
                'submitted_at' => $submittedAt,
            ])->save();

            $this->auditService->record($auditAction, $report, $actor, [
                'incident_id' => $incident->id,
                'user_id' => $user->id,
                'submitted_for_user_id' => $user->id,
            ]);

            return $report->refresh();
        });

        $this->incidentReportService->refreshStored($incident->refresh(), preserveExistingMaps: true);

        return $report;
    }

    public function finalize(Incident $incident, User $user, User $actor): PilotIncidentReport
    {
        $this->assertCanReport($incident, $user);

        $report = $this->existingReport($incident, $user);
        if ($report === null || $report->status !== 'submitted') {
            throw ValidationException::withMessages([
                'report' => ['Dit inzetverslag moet eerst worden ingediend voordat het definitief kan worden gemaakt.'],
            ]);
        }

        if ($report->isFinalized()) {
            return $report;
        }

        $report->forceFill(['finalized_at' => now()])->save();
        $this->auditService->record($actor->is($user) ? 'pilot_incident_report.finalized' : 'pilot_incident_report.finalized_by_admin', $report, $actor, [
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'submitted_for_user_id' => $user->id,
        ]);

        $this->incidentReportService->refreshStored($incident->refresh(), preserveExistingMaps: true);

        return $report->refresh();
    }

    /**
     * @param array<string, mixed> $customFields
     * @return array{summary: ?string, observations: ?string, actions_taken: ?string, result: ?string, issues: ?string, equipment_used: ?string, flight_minutes: ?int}
     */
    private function standardValuesFromCustomFields(array $customFields): array
    {
        return [
            'summary' => $this->stringValue($customFields['summary'] ?? null),
            'observations' => $this->stringValue($customFields['observations'] ?? null),
            'actions_taken' => $this->stringValue($customFields['actions_taken'] ?? null),
            'result' => $this->stringValue($customFields['result'] ?? null),
            'issues' => $this->stringValue($customFields['issues'] ?? null),
            'equipment_used' => $this->stringValue($customFields['equipment_used'] ?? null),
            'flight_minutes' => $this->flightMinutesFromValue($customFields['flight_time'] ?? null),
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function flightMinutesFromValue(mixed $value): ?int
    {
        if (is_array($value) && isset($value['duration_minutes']) && is_numeric($value['duration_minutes'])) {
            return (int) $value['duration_minutes'];
        }

        return null;
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

    private function assertReportCanBeEdited(PilotIncidentReport $report): void
    {
        if ($report->canBeEdited()) {
            return;
        }

        throw ValidationException::withMessages([
            'report' => ['Dit inzetverslag is definitief en kan niet meer worden aangepast.'],
        ]);
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
