<?php

namespace App\Services;

use App\Events\AvailabilityChanged;
use App\Events\IncidentChanged;
use App\Models\AvailabilityStatus;
use App\Models\Incident;
use App\Models\User;
use App\Models\UserVacation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StatusService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly LocationService $locationService,
    ) {}

    public function setStatus(User $user, string $status, ?User $actor, ?string $reason = null, bool $systemApplied = false): AvailabilityStatus
    {
        $record = DB::transaction(function () use ($user, $status, $actor, $reason, $systemApplied): AvailabilityStatus {
            $previousStatus = $this->latestStatus($user);
            $isAvailable = $status === 'available';

            if ($isAvailable && $this->hasActiveVacation($user)) {
                throw ValidationException::withMessages(['status' => ['Deze gebruiker staat op vakantie en kan niet beschikbaar worden gezet.']]);
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
            $this->transitionAcceptedIncidentsToInProgressWhenEveryoneOnScene($user, $actor);
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

    private function dispatchAvailabilityChanged(AvailabilityStatus $status): void
    {
        try {
            AvailabilityChanged::dispatch($status);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function transitionAcceptedIncidentsToInProgressWhenEveryoneOnScene(User $user, User $actor): void
    {
        $incidents = Incident::query()
            ->whereIn('status', ['active', 'dispatching'])
            ->whereHas('dispatchRequests', fn ($dispatches) => $dispatches
                ->whereIn('status', ['sent', 'escalated'])
                ->whereHas('recipients', fn ($recipients) => $recipients
                    ->where('user_id', $user->id)
                    ->where('response_status', 'accepted')))
            ->with(['dispatchRequests' => fn ($dispatches) => $dispatches
                ->whereIn('status', ['sent', 'escalated'])
                ->with('recipients')])
            ->get();

        foreach ($incidents as $incident) {
            $acceptedUserIds = $incident->dispatchRequests
                ->flatMap(fn ($dispatch) => $dispatch->recipients)
                ->filter(fn ($recipient): bool => $recipient->response_status === 'accepted')
                ->pluck('user_id')
                ->unique()
                ->values();

            if ($acceptedUserIds->isEmpty()) {
                continue;
            }

            $latestStatuses = AvailabilityStatus::query()
                ->latestPerUser()
                ->whereIn('user_id', $acceptedUserIds->all())
                ->pluck('status', 'user_id');

            $everyoneOnScene = $acceptedUserIds
                ->every(fn (string $userId): bool => $latestStatuses->get($userId) === 'on_scene');

            if ($everyoneOnScene) {
                $this->transitionIncidentStatus(
                    $incident,
                    $actor,
                    'in_progress',
                    'Automatisch naar uitvoering gezet omdat alle geaccepteerde opkomers op locatie zijn.',
                );
            }
        }
    }

    private function transitionIncidentStatus(Incident $incident, User $actor, string $status, string $reason): void
    {
        DB::transaction(function () use ($incident, $actor, $status, $reason): void {
            $incident->refresh();
            if (! in_array($incident->status, ['active', 'dispatching'], true) || $incident->status === $status) {
                return;
            }

            $previousStatus = $incident->status;
            $incident->forceFill(['status' => $status])->save();
            $incident->statusHistory()->create([
                'from_status' => $previousStatus,
                'to_status' => $status,
                'changed_by' => $actor->id,
                'changed_by_name' => $actor->name,
                'changed_by_email' => $actor->email,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $this->auditService->record('incidents.status_auto_updated', $incident, $actor, [
                'from_status' => $previousStatus,
                'to_status' => $status,
            ], $reason);
            $this->dispatchIncidentChanged($incident->refresh(), 'status_auto_updated');
        });
    }

    private function dispatchIncidentChanged(Incident $incident, string $action): void
    {
        try {
            IncidentChanged::dispatch($incident, $action);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function hasActiveVacation(User $user): bool
    {
        return UserVacation::query()
            ->where('user_id', $user->id)
            ->open()
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today())
            ->exists();
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
