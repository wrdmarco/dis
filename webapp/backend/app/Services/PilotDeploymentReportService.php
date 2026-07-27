<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\PilotDeploymentReport;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PilotDeploymentReportService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly PilotDeploymentReportFormService $formService,
        private readonly PilotDeploymentReportDroneSnapshotService $droneSnapshotService,
        private readonly DeploymentReportService $deploymentReportService,
    ) {}

    public function ensureForOnScene(User $user, User $actor): void
    {
        $this->acceptedDeploymentsForUser($user, ['active', 'dispatching', 'in_progress'])->each(function (Deployment $deployment) use ($user, $actor): void {
            $report = $this->ensureReport($deployment, $user);

            $this->auditService->record('pilot_deployment_report.prepared', $report, $actor, [
                'deployment_id' => $deployment->id,
                'user_id' => $user->id,
            ]);
        });
    }

    public function show(Deployment $deployment, User $user): PilotDeploymentReport
    {
        $this->assertCanReport($deployment, $user);

        $existing = $this->existingReport($deployment, $user);
        if ($existing !== null) {
            return $existing;
        }

        $this->assertOnScene($user);

        return $this->ensureReport($deployment, $user)->refresh();
    }

    public function showForActor(Deployment $deployment, User $user, User $actor): PilotDeploymentReport
    {
        $this->assertCanReport($deployment, $user);

        $report = $this->ensureReport($deployment, $user)->refresh();
        $this->auditService->record('pilot_deployment_report.opened_by_admin', $report, $actor, [
            'deployment_id' => $deployment->id,
            'user_id' => $user->id,
        ]);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(Deployment $deployment, User $user, array $data): PilotDeploymentReport
    {
        $this->assertCanReport($deployment, $user);
        $this->assertCanSubmit($deployment, $user);

        return $this->storeSubmission($deployment, $user, $user, $data, 'pilot_deployment_report.submitted');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submitForActor(Deployment $deployment, User $user, User $actor, array $data): PilotDeploymentReport
    {
        $this->assertCanReport($deployment, $user);

        return $this->storeSubmission($deployment, $user, $actor, $data, 'pilot_deployment_report.submitted_by_admin');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSubmission(Deployment $deployment, User $user, User $actor, array $data, string $auditAction): PilotDeploymentReport
    {
        $report = DB::transaction(function () use ($deployment, $user, $actor, $data, $auditAction): PilotDeploymentReport {
            $report = $this->ensureReport($deployment, $user);
            $this->assertReportCanBeEdited($report);

            $submittedAt = $report->submitted_at ?? now();
            $customFields = $this->formService->normalizeCustomValues($data);
            $standardValues = $this->standardValuesFromCustomFields($customFields);
            $fieldKeys = $this->formService->droneFieldKeys();
            $existingCustomFields = is_array($report->custom_fields) ? $report->custom_fields : [];
            $existingSnapshots = is_array($report->drone_usage_snapshot) ? $report->drone_usage_snapshot : [];
            $customFields = $this->droneSnapshotService->preserveHistoricalSelections(
                $customFields,
                $existingCustomFields,
                $existingSnapshots,
                $fieldKeys,
            );
            $droneUsageSnapshot = $this->droneSnapshotService->capture(
                $customFields,
                $existingSnapshots,
                $fieldKeys,
            );
            $report->fill([
                'summary' => $data['summary'] ?? $standardValues['summary'],
                'observations' => $data['observations'] ?? $standardValues['observations'],
                'actions_taken' => $data['actions_taken'] ?? $standardValues['actions_taken'],
                'result' => $data['result'] ?? $standardValues['result'],
                'issues' => $data['issues'] ?? $standardValues['issues'],
                'equipment_used' => $data['equipment_used'] ?? $standardValues['equipment_used'],
                'flight_minutes' => $data['flight_minutes'] ?? $standardValues['flight_minutes'],
                'custom_fields' => $customFields,
                'drone_usage_snapshot' => $droneUsageSnapshot,
                'status' => 'submitted',
                'submitted_at' => $submittedAt,
            ])->save();

            $this->auditService->record($auditAction, $report, $actor, [
                'deployment_id' => $deployment->id,
                'user_id' => $user->id,
                'submitted_for_user_id' => $user->id,
            ]);

            return $report->refresh();
        });

        $this->deploymentReportService->refreshStored($deployment->refresh(), preserveExistingMaps: true);

        return $report;
    }

    public function finalize(Deployment $deployment, User $user, User $actor): PilotDeploymentReport
    {
        $this->assertCanReport($deployment, $user);

        $report = $this->existingReport($deployment, $user);
        if ($report === null || $report->status !== 'submitted') {
            throw ValidationException::withMessages([
                'report' => ['Dit inzetverslag moet eerst worden ingediend voordat het definitief kan worden gemaakt.'],
            ]);
        }

        if ($report->isFinalized()) {
            return $report;
        }

        $report->forceFill(['finalized_at' => now()])->save();
        $this->auditService->record($actor->is($user) ? 'pilot_deployment_report.finalized' : 'pilot_deployment_report.finalized_by_admin', $report, $actor, [
            'deployment_id' => $deployment->id,
            'user_id' => $user->id,
            'submitted_for_user_id' => $user->id,
        ]);

        $this->deploymentReportService->refreshStored($deployment->refresh(), preserveExistingMaps: true);

        return $report->refresh();
    }

    /**
     * @param  array<string, mixed>  $customFields
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
            'flight_minutes' => $this->flightMinutesFromCustomFields($customFields),
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

    /** @param  array<string, mixed>  $customFields */
    private function flightMinutesFromCustomFields(array $customFields): ?int
    {
        $minutes = $this->flightMinutesFromValue($customFields['flight_time'] ?? null);
        if ($minutes !== null) {
            return $minutes;
        }

        $legacyMinutes = $customFields['flight_minutes'] ?? null;
        if (! is_int($legacyMinutes) || $legacyMinutes < 0 || $legacyMinutes > 1440) {
            return null;
        }

        return $legacyMinutes;
    }

    private function ensureReport(Deployment $deployment, User $user): PilotDeploymentReport
    {
        return PilotDeploymentReport::query()->firstOrCreate(
            [
                'deployment_id' => $deployment->id,
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

    private function existingReport(Deployment $deployment, User $user): ?PilotDeploymentReport
    {
        return PilotDeploymentReport::query()
            ->where('deployment_id', $deployment->id)
            ->where('user_id', $user->id)
            ->first();
    }

    private function assertCanReport(Deployment $deployment, User $user): void
    {
        if (! $this->acceptedDeploymentsForUser($user, ['active', 'dispatching', 'in_progress', 'resolved', 'cancelled'])->contains('id', $deployment->id)) {
            throw ValidationException::withMessages([
                'deployment' => ['Alleen geaccepteerde opkomers kunnen een inzetverslag voor deze inzet invullen.'],
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

    private function assertCanSubmit(Deployment $deployment, User $user): void
    {
        if (in_array($deployment->status, ['resolved', 'cancelled'], true)) {
            return;
        }

        $this->assertOnScene($user);
    }

    private function assertReportCanBeEdited(PilotDeploymentReport $report): void
    {
        if ($report->canBeEdited()) {
            return;
        }

        throw ValidationException::withMessages([
            'report' => ['Dit inzetverslag is definitief en kan niet meer worden aangepast.'],
        ]);
    }

    /**
     * @return Collection<int, Deployment>
     */
    private function acceptedDeploymentsForUser(User $user, array $statuses): Collection
    {
        return Deployment::query()
            ->whereIn('status', $statuses)
            ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                ->whereIn('status', ['sent', 'escalated'])
                ->whereHas('recipients', fn ($recipients) => $recipients
                    ->where('user_id', $user->id)
                    ->where('response_status', 'accepted')))
            ->get();
    }
}
