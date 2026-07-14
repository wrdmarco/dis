<?php

namespace App\Services;

use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class IncidentAccessService
{
    private const ATTENDANCE_DISPATCH_STATUSES = ['sent', 'escalated'];

    private const TERMINAL_INCIDENT_STATUSES = ['resolved', 'cancelled'];

    public function assertCanListIncidents(User $actor): void
    {
        if ($actor->isOperatorClient()) {
            if ($this->hasOperatorIncidentPermission($actor)) {
                return;
            }
        } elseif ($actor->hasPermission('incidents.view')) {
            return;
        }

        throw new AuthorizationException('This action is unauthorized.');
    }

    public function assertCanListDispatches(User $actor): void
    {
        if ($actor->isOperatorClient()) {
            if ($this->hasOperatorDispatchPermission($actor)) {
                return;
            }
        } elseif ($actor->hasPermission('incidents.dispatch.view')) {
            return;
        }

        throw new AuthorizationException('This action is unauthorized.');
    }

    public function assertCanViewIncident(User $actor, Incident $incident): void
    {
        if (! $this->canViewIncident($actor, $incident)) {
            throw new AuthorizationException('The incident is not assigned to this user.');
        }
    }

    public function canViewIncident(User $actor, Incident $incident): bool
    {
        if (! $actor->isOperatorClient()) {
            return $actor->hasPermission('incidents.view');
        }

        if (! $this->hasOperatorIncidentPermission($actor)) {
            return false;
        }

        return $this->scopeIncidents(Incident::query()->whereKey($incident->getKey()), $actor)->exists();
    }

    /**
     * @param  Builder<Incident>  $query
     * @return Builder<Incident>
     */
    public function scopeIncidents(Builder $query, User $actor): Builder
    {
        if (! $actor->isOperatorClient()) {
            return $actor->hasPermission('incidents.view')
                ? $query
                : $query->whereRaw('1 = 0');
        }

        if (! $this->hasOperatorIncidentPermission($actor)) {
            return $query->whereRaw('1 = 0');
        }

        $userId = (string) $actor->id;

        return $query->where(function (Builder $incidents) use ($userId): void {
            $incidents
                ->where(function (Builder $active) use ($userId): void {
                    $active
                        ->whereNotIn('status', self::TERMINAL_INCIDENT_STATUSES)
                        ->where(function (Builder $incidentType) use ($userId): void {
                            $incidentType
                                ->where(function (Builder $normal) use ($userId): void {
                                    $normal
                                        ->where('is_test', false)
                                        ->whereHas('dispatchRequests', function (Builder $dispatches) use ($userId): void {
                                            $this->scopeActiveOperatorDispatches($dispatches, $userId);
                                        });
                                })
                                ->orWhere(function (Builder $test) use ($userId): void {
                                    $test
                                        ->where('is_test', true)
                                        ->whereHas('dispatchRequests', fn (Builder $dispatches) => $dispatches
                                            ->whereIn('status', ['draft', ...self::ATTENDANCE_DISPATCH_STATUSES])
                                            ->whereHas('recipients', fn (Builder $recipients) => $recipients
                                                ->where('user_id', $userId)
                                                ->where('response_status', 'pending')));
                                });
                        });
                })
                ->orWhere(function (Builder $closed) use ($userId): void {
                    $closed
                        ->whereIn('status', self::TERMINAL_INCIDENT_STATUSES)
                        ->where('is_test', false)
                        ->whereHas('dispatchRequests', fn (Builder $dispatches) => $dispatches
                            ->whereIn('status', self::ATTENDANCE_DISPATCH_STATUSES)
                            ->whereHas('recipients', fn (Builder $recipients) => $recipients
                                ->where('user_id', $userId)
                                ->where('response_status', 'accepted')))
                        ->whereDoesntHave('pilotReports', fn (Builder $reports) => $reports
                            ->where('user_id', $userId)
                            ->whereNotNull('finalized_at'));
                });
        });
    }

    public function assertCanViewDispatch(User $actor, DispatchRequest $dispatch): void
    {
        if (! $this->canViewDispatch($actor, $dispatch)) {
            throw new AuthorizationException('The dispatch is not assigned to this user.');
        }
    }

    public function canViewDispatch(User $actor, DispatchRequest $dispatch): bool
    {
        if (! $actor->isOperatorClient()) {
            return $actor->hasPermission('incidents.dispatch.view');
        }

        if (! $this->hasOperatorDispatchPermission($actor)) {
            return false;
        }

        return $this->scopeDispatches(DispatchRequest::query()->whereKey($dispatch->getKey()), $actor)->exists();
    }

    /**
     * @param Builder<DispatchRequest>|Relation<DispatchRequest, *, *> $query
     * @return Builder<DispatchRequest>|Relation<DispatchRequest, *, *>
     */
    public function scopeDispatches(Builder|Relation $query, User $actor): Builder|Relation
    {
        if (! $actor->isOperatorClient()) {
            return $actor->hasPermission('incidents.dispatch.view')
                ? $query
                : $query->whereRaw('1 = 0');
        }

        if (! $this->hasOperatorDispatchPermission($actor)) {
            return $query->whereRaw('1 = 0');
        }

        $userId = (string) $actor->id;

        return $query
            ->whereHas('incident', fn (Builder $incidents) => $this->scopeIncidents($incidents, $actor))
            ->where(function (Builder $dispatches) use ($userId): void {
                $this->scopeActiveOperatorDispatches($dispatches, $userId);
            });
    }

    public function relevantDispatch(Incident $incident, User $actor): ?DispatchRequest
    {
        if (! $actor->isOperatorClient()) {
            return null;
        }

        return $this->scopeDispatches(
            $incident->dispatchRequests()
                ->with(['recipients' => fn ($recipients) => $recipients->where('user_id', $actor->id)])
                ->latest(),
            $actor,
        )->first();
    }

    /**
     * @param  Builder<DispatchRequest>  $dispatches
     */
    private function scopeActiveOperatorDispatches(Builder $dispatches, string $userId): void
    {
        $dispatches->where(function (Builder $eligible) use ($userId): void {
            $eligible
                ->where(function (Builder $preannouncement) use ($userId): void {
                    $preannouncement
                        ->where('status', 'draft')
                        ->whereHas('recipients', fn (Builder $recipients) => $recipients
                            ->where('user_id', $userId)
                            ->where('response_status', 'pending'));
                })
                ->orWhere(function (Builder $attendance) use ($userId): void {
                    $attendance
                        ->whereIn('status', self::ATTENDANCE_DISPATCH_STATUSES)
                        ->whereHas('recipients', fn (Builder $recipients) => $recipients
                            ->where('user_id', $userId)
                            ->whereIn('response_status', ['pending', 'accepted']));
                });
        });
    }

    private function hasOperatorIncidentPermission(User $actor): bool
    {
        return $actor->hasClientPermission('incidents.assigned.view', 'operator')
            || $actor->hasClientPermission('incidents.view', 'operator');
    }

    private function hasOperatorDispatchPermission(User $actor): bool
    {
        return $actor->hasClientPermission('incidents.assigned.view', 'operator')
            || $actor->hasClientPermission('incidents.dispatch.view', 'operator');
    }
}
