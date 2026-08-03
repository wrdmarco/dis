<?php

namespace App\Services;

use App\Events\AvailabilityChanged;
use App\Events\DeploymentChanged;
use App\Models\AvailabilityStatus;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StatusService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly LocationService $locationService,
        private readonly PilotDeploymentReportService $pilotDeploymentReportService,
        private readonly AvailabilityScheduleResolver $availabilityScheduleResolver,
    ) {}

    public function setStatus(User $user, string $status, ?User $actor, ?string $reason = null, bool $systemApplied = false): AvailabilityStatus
    {
        return $this->storeStatus($user, $status, $actor, $reason, $systemApplied);
    }

    /** @param callable(): void $precondition */
    public function setStatusWithPrecondition(
        User $user,
        string $status,
        ?User $actor,
        ?string $reason,
        callable $precondition,
        bool $systemApplied = false,
    ): AvailabilityStatus {
        return $this->storeStatus($user, $status, $actor, $reason, $systemApplied, $precondition);
    }

    /** @param (callable(): void)|null $precondition */
    private function storeStatus(
        User $user,
        string $status,
        ?User $actor,
        ?string $reason,
        bool $systemApplied,
        ?callable $precondition = null,
    ): AvailabilityStatus {
        $record = DB::transaction(function () use ($user, $status, $actor, $reason, $systemApplied, $precondition): AvailabilityStatus {
            if ($precondition !== null) {
                $precondition();
            }

            $previousStatus = $this->latestStatus($user);
            $isAvailable = $status === 'available';

            if ($isAvailable && ! $systemApplied && ! $this->availabilityScheduleResolver->availabilityFor($user)['is_available']) {
                throw ValidationException::withMessages(['status' => ['Deze gebruiker heeft voor vandaag een niet-beschikbare planning en kan niet beschikbaar worden gezet.']]);
            }

            if ($isAvailable && ! $user->push_enabled) {
                throw ValidationException::withMessages(['status' => ['Push notifications are required before a user can be available.']]);
            }

            $record = AvailabilityStatus::query()->create([
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'status' => $status,
                'is_available' => $isAvailable,
                'is_system_applied' => $systemApplied,
                'changed_by' => $actor?->id,
                'changed_by_name' => $actor?->name,
                'changed_by_email' => $actor?->email,
                'reason' => $reason,
                'effective_at' => now(),
            ]);

            $this->auditService->record($systemApplied ? 'status.system_updated' : 'status.updated', $user, $actor, [
                'from_status' => $previousStatus?->status,
                'to_status' => $status,
                'is_available' => $isAvailable,
                'is_system_applied' => $systemApplied,
            ], $reason);

            return $record;
        });

        $this->dispatchAvailabilityChanged($record);
        if ($status === 'on_scene' && $actor !== null) {
            $this->locationService->stopForUser($user, $actor);
            $this->pilotDeploymentReportService->ensureForOnScene($user, $actor);
            $this->transitionAcceptedDeploymentsToInProgressWhenEveryoneOnScene($user, $actor);
        }

        return $record;
    }

    public function enforcePushUnavailable(User $user): void
    {
        if (! $user->push_enabled) {
            $previousStatus = $this->latestStatus($user);
            $record = AvailabilityStatus::query()->create([
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'status' => 'unavailable',
                'is_available' => false,
                'is_system_applied' => true,
                'changed_by' => null,
                'reason' => 'Push notifications disabled.',
                'effective_at' => now(),
            ]);
            $this->auditService->record('status.system_updated', $user, null, [
                'from_status' => $previousStatus?->status,
                'to_status' => 'unavailable',
                'is_available' => false,
                'is_system_applied' => true,
            ], 'Push notifications disabled.');
            $this->dispatchAvailabilityChanged($record);
        }
    }

    /**
     * Reconcile a dispatching deployment after its participant set changed.
     *
     * The caller must own the surrounding workflow transaction and publish
     * its deployment change only after that transaction commits.
     */
    public function reconcileDeploymentProgressAfterParticipantChange(Deployment $deployment, User $actor): bool
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Participant reconciliation requires an active database transaction.');
        }

        $lockedDeployment = Deployment::query()
            ->whereKey($deployment->getKey())
            ->lockForUpdate()
            ->first();
        if ($lockedDeployment === null
            || $lockedDeployment->is_test
            || $lockedDeployment->status !== 'dispatching') {
            return false;
        }

        $dispatchParticipantUserIds = $lockedDeployment->dispatchRequests()
            ->whereIn('status', ['sent', 'escalated'])
            ->with('recipients')
            ->get()
            ->flatMap(fn ($dispatch) => $dispatch->recipients)
            ->filter(fn ($recipient): bool => $recipient->response_status === 'accepted')
            ->pluck('user_id');
        $manualParticipantUserIds = $lockedDeployment->pilotAssignments()
            ->whereNotNull('user_id')
            ->pluck('user_id');
        $participantUserIds = $dispatchParticipantUserIds
            ->merge($manualParticipantUserIds)
            ->filter(fn (mixed $userId): bool => is_string($userId) && $userId !== '')
            ->unique()
            ->values();
        if ($participantUserIds->isEmpty()) {
            return false;
        }

        $latestStatuses = AvailabilityStatus::query()
            ->latestPerUser()
            ->whereIn('user_id', $participantUserIds->all())
            ->pluck('status', 'user_id');
        if (! $participantUserIds->every(
            fn (string $userId): bool => $latestStatuses->get($userId) === 'on_scene',
        )) {
            return false;
        }

        $reason = 'Automatisch naar uitvoering gezet omdat alle resterende gekoppelde opkomers op locatie zijn.';
        $previousStatus = $lockedDeployment->status;
        $lockedDeployment->forceFill(['status' => 'in_progress'])->save();
        $lockedDeployment->statusHistory()->create([
            'from_status' => $previousStatus,
            'to_status' => 'in_progress',
            'changed_by' => $actor->id,
            'changed_by_name' => $actor->name,
            'changed_by_email' => $actor->email,
            'reason' => $reason,
            'created_at' => now(),
        ]);
        $this->auditService->record('deployments.status_auto_updated', $lockedDeployment, $actor, [
            'from_status' => $previousStatus,
            'to_status' => 'in_progress',
        ], $reason);

        return true;
    }

    private function dispatchAvailabilityChanged(AvailabilityStatus $status): void
    {
        try {
            AvailabilityChanged::dispatch($status);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function transitionAcceptedDeploymentsToInProgressWhenEveryoneOnScene(User $user, User $actor): void
    {
        $deployments = Deployment::query()
            ->where('status', 'dispatching')
            ->where(function ($participation) use ($user): void {
                $participation
                    ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                        ->whereIn('status', ['sent', 'escalated'])
                        ->whereHas('recipients', fn ($recipients) => $recipients
                            ->where('user_id', $user->id)
                            ->where('response_status', 'accepted')))
                    ->orWhereHas('pilotAssignments', fn ($assignments) => $assignments
                        ->where('user_id', $user->id));
            })
            ->with(['dispatchRequests' => fn ($dispatches) => $dispatches
                ->whereIn('status', ['sent', 'escalated'])
                ->with('recipients'), 'pilotAssignments'])
            ->get();

        foreach ($deployments as $deployment) {
            $participantUserIds = $deployment->dispatchRequests
                ->flatMap(fn ($dispatch) => $dispatch->recipients)
                ->filter(fn ($recipient): bool => $recipient->response_status === 'accepted')
                ->pluck('user_id')
                ->merge($deployment->pilotAssignments->pluck('user_id'))
                ->filter(fn (mixed $userId): bool => is_string($userId) && $userId !== '')
                ->unique()
                ->values();

            if ($participantUserIds->isEmpty()) {
                continue;
            }

            $latestStatuses = AvailabilityStatus::query()
                ->latestPerUser()
                ->whereIn('user_id', $participantUserIds->all())
                ->pluck('status', 'user_id');

            $everyoneOnScene = $participantUserIds
                ->every(fn (string $userId): bool => $latestStatuses->get($userId) === 'on_scene');

            if ($everyoneOnScene) {
                $this->transitionDeploymentStatus(
                    $deployment,
                    $actor,
                    'in_progress',
                    'Automatisch naar uitvoering gezet omdat alle geaccepteerde opkomers op locatie zijn.',
                );
            }
        }
    }

    private function transitionDeploymentStatus(Deployment $deployment, User $actor, string $status, string $reason): void
    {
        DB::transaction(function () use ($deployment, $actor, $status, $reason): void {
            $deployment = Deployment::query()
                ->lockForUpdate()
                ->find($deployment->getKey());
            if ($deployment === null) {
                return;
            }
            if ($deployment->status !== 'dispatching' || $status !== 'in_progress') {
                return;
            }

            $previousStatus = $deployment->status;
            $deployment->forceFill(['status' => $status])->save();
            $deployment->statusHistory()->create([
                'from_status' => $previousStatus,
                'to_status' => $status,
                'changed_by' => $actor->id,
                'changed_by_name' => $actor->name,
                'changed_by_email' => $actor->email,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $this->auditService->record('deployments.status_auto_updated', $deployment, $actor, [
                'from_status' => $previousStatus,
                'to_status' => $status,
            ], $reason);
            $this->dispatchDeploymentChanged($deployment->refresh(), 'status_auto_updated');
        });
    }

    private function dispatchDeploymentChanged(Deployment $deployment, string $action): void
    {
        try {
            DeploymentChanged::dispatch($deployment, $action);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function latestStatus(User $user): ?AvailabilityStatus
    {
        return $user->statuses()
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
