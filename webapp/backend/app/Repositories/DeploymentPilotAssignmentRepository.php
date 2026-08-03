<?php

namespace App\Repositories;

use App\Models\Deployment;
use App\Models\DeploymentPilotAssignment;
use App\Models\DispatchRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<DeploymentPilotAssignment>
 */
final class DeploymentPilotAssignmentRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return DeploymentPilotAssignment::class;
    }

    /** @return Collection<int, DeploymentPilotAssignment> */
    public function forDeployment(Deployment $deployment): Collection
    {
        return $this->query()
            ->with(['user' => fn ($users) => $users
                ->withTrashed()
                ->with([
                    'teams',
                    'statuses' => fn ($statuses) => $statuses->latestPerUser(),
                ])])
            ->where('deployment_id', $deployment->id)
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, DispatchRecipient> */
    public function acceptedDispatchRecipients(Deployment $deployment): Collection
    {
        return DispatchRecipient::query()
            ->with([
                'user' => fn ($users) => $users
                    ->withTrashed()
                    ->with([
                        'teams',
                        'statuses' => fn ($statuses) => $statuses->latestPerUser(),
                    ]),
                'dispatchRequest:id,deployment_id,status,sent_at,created_at',
            ])
            ->where('response_status', 'accepted')
            ->whereHas('dispatchRequest', fn (Builder $dispatches) => $dispatches
                ->where('deployment_id', $deployment->id)
                ->whereIn('status', ['sent', 'escalated']))
            ->orderByDesc('responded_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, User> */
    public function candidates(Deployment $deployment, ?string $search): Collection
    {
        $normalized = is_string($search) && trim($search) !== ''
            ? trim($search)
            : null;
        $baseTeamCode = (string) config('dis.teams.base_team_code', 'OCP');

        return User::query()
            ->withoutStoreReview()
            ->with([
                'teams',
                'statuses' => fn ($statuses) => $statuses->latestPerUser(),
            ])
            ->where('account_status', 'active')
            ->whereHas('roles', fn (Builder $roles) => $roles
                ->where('roles.name', 'operator-pilot'))
            ->whereHas('teams', fn (Builder $teams) => $teams
                ->where('teams.code', $baseTeamCode))
            ->whereDoesntHave('pilotAssignments', fn (Builder $assignments) => $assignments
                ->where('deployment_id', $deployment->id))
            ->whereNotExists(function ($recipients) use ($deployment): void {
                $recipients
                    ->selectRaw('1')
                    ->from('dispatch_recipients')
                    ->join(
                        'dispatch_requests',
                        'dispatch_requests.id',
                        '=',
                        'dispatch_recipients.dispatch_request_id',
                    )
                    ->whereColumn('dispatch_recipients.user_id', 'users.id')
                    ->where('dispatch_requests.deployment_id', $deployment->id);
            })
            ->when($normalized !== null, function (Builder $users) use ($normalized): void {
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($normalized));
                $pattern = '%'.$escaped.'%';
                $users->where(function (Builder $searchQuery) use ($pattern): void {
                    $searchQuery
                        ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'name', 'email']);
    }

    /** @param array<string, mixed> $attributes */
    public function createAssignment(array $attributes): DeploymentPilotAssignment
    {
        return $this->query()->create($attributes);
    }

    public function eligiblePilotForUpdate(string $userId): ?User
    {
        $baseTeamCode = (string) config('dis.teams.base_team_code', 'OCP');

        return User::query()
            ->withoutStoreReview()
            ->whereKey($userId)
            ->where('account_status', 'active')
            ->whereHas('roles', fn (Builder $roles) => $roles
                ->where('roles.name', 'operator-pilot'))
            ->whereHas('teams', fn (Builder $teams) => $teams
                ->where('teams.code', $baseTeamCode))
            ->lockForUpdate()
            ->first();
    }

    public function assignmentForUpdate(Deployment $deployment, User $user): ?DeploymentPilotAssignment
    {
        return $this->query()
            ->where('deployment_id', $deployment->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
    }

    public function assignmentForDeploymentForUpdate(
        Deployment $deployment,
        DeploymentPilotAssignment $assignment,
    ): DeploymentPilotAssignment {
        return $this->query()
            ->whereKey($assignment->id)
            ->where('deployment_id', $deployment->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function isDispatchRecipient(Deployment $deployment, User $user): bool
    {
        return DispatchRecipient::query()
            ->where('user_id', $user->id)
            ->whereHas('dispatchRequest', fn (Builder $dispatches) => $dispatches
                ->where('deployment_id', $deployment->id))
            ->exists();
    }

    public function isOperationalParticipant(Deployment $deployment, User $user): bool
    {
        if ($this->query()
            ->where('deployment_id', $deployment->id)
            ->where('user_id', $user->id)
            ->exists()) {
            return true;
        }

        return DispatchRecipient::query()
            ->where('user_id', $user->id)
            ->where('response_status', 'accepted')
            ->whereHas('dispatchRequest', fn (Builder $dispatches) => $dispatches
                ->where('deployment_id', $deployment->id)
                ->whereIn('status', ['sent', 'escalated']))
            ->exists();
    }
}
