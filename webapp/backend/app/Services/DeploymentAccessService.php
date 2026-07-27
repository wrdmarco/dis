<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\DispatchRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

final class DeploymentAccessService
{
    private const ATTENDANCE_DISPATCH_STATUSES = ['sent', 'escalated'];

    private const TERMINAL_DEPLOYMENT_STATUSES = ['resolved', 'cancelled'];

    public function assertCanListDeployments(User $actor): void
    {
        if ($this->canListDeployments($actor)) {
            return;
        }

        throw new AuthorizationException('This action is unauthorized.');
    }

    public function canListDeployments(User $actor): bool
    {
        return $actor->isOperatorClient()
            ? $this->hasOperatorDeploymentPermission($actor)
            : $this->hasWebDeploymentViewPermission($actor);
    }

    public function assertCanListDispatches(User $actor): void
    {
        if ($actor->isOperatorClient()) {
            if ($this->hasOperatorDispatchPermission($actor)) {
                return;
            }
        } elseif ($actor->hasPermission('deployments.dispatch.view')) {
            return;
        }

        throw new AuthorizationException('This action is unauthorized.');
    }

    public function assertCanViewDeployment(User $actor, Deployment $deployment): void
    {
        if (! $this->canViewDeployment($actor, $deployment)) {
            throw new AuthorizationException('Deze inzet is niet aan deze gebruiker toegewezen.');
        }
    }

    public function canViewDeployment(User $actor, Deployment $deployment): bool
    {
        if (! $actor->isOperatorClient()) {
            return $this->hasWebDeploymentViewPermission($actor);
        }

        if (! $this->hasOperatorDeploymentPermission($actor)) {
            return false;
        }

        return $this->scopeDeployments(Deployment::query()->whereKey($deployment->getKey()), $actor)->exists();
    }

    /**
     * @param  Builder<Deployment>  $query
     * @return Builder<Deployment>
     */
    public function scopeDeployments(Builder $query, User $actor): Builder
    {
        if (! $actor->isOperatorClient()) {
            return $this->hasWebDeploymentViewPermission($actor)
                ? $query
                : $query->whereRaw('1 = 0');
        }

        if (! $this->hasOperatorDeploymentPermission($actor)) {
            return $query->whereRaw('1 = 0');
        }

        $userId = (string) $actor->id;

        return $query->where(function (Builder $deployments) use ($userId): void {
            $deployments
                ->where(function (Builder $active) use ($userId): void {
                    $active
                        ->whereNotIn('status', self::TERMINAL_DEPLOYMENT_STATUSES)
                        ->where(function (Builder $deploymentType) use ($userId): void {
                            $deploymentType
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
                        ->whereIn('status', self::TERMINAL_DEPLOYMENT_STATUSES)
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
            return $actor->hasPermission('deployments.dispatch.view');
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
            return $actor->hasPermission('deployments.dispatch.view')
                ? $query
                : $query->whereRaw('1 = 0');
        }

        if (! $this->hasOperatorDispatchPermission($actor)) {
            return $query->whereRaw('1 = 0');
        }

        $userId = (string) $actor->id;

        return $query
            ->whereHas('deployment', fn (Builder $deployments) => $this->scopeDeployments($deployments, $actor))
            ->where(function (Builder $dispatches) use ($userId): void {
                $this->scopeActiveOperatorDispatches($dispatches, $userId);
            });
    }

    public function relevantDispatch(Deployment $deployment, User $actor): ?DispatchRequest
    {
        if (! $actor->isOperatorClient()) {
            return null;
        }

        return $this->scopeDispatches(
            $deployment->dispatchRequests()
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

    private function hasOperatorDeploymentPermission(User $actor): bool
    {
        return $actor->hasClientPermission('deployments.assigned.view', 'operator')
            || $actor->hasClientPermission('deployments.view', 'operator');
    }

    private function hasWebDeploymentViewPermission(User $actor): bool
    {
        return $actor->hasPermission('deployments.view')
            || ($actor->currentClientType() === 'web' && $actor->hasPermission('deployments.manage'));
    }

    private function hasOperatorDispatchPermission(User $actor): bool
    {
        return $actor->hasClientPermission('deployments.assigned.view', 'operator')
            || $actor->hasClientPermission('deployments.dispatch.view', 'operator');
    }
}
