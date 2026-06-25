<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UserService
{
    public function __construct(private readonly AuditService $auditService) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            $user = User::query()->create($data);
            $this->auditService->record('users.created', $user, $actor);

            return $user->load(['roles', 'teams']);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $before = $user->only(array_keys($data));
            $user->update($data);
            $this->auditService->record('users.updated', $user, $actor, ['before' => $before, 'after' => $user->only(array_keys($data))]);

            return $user->refresh()->load(['roles', 'teams']);
        });
    }

    public function assignRole(User $user, Role $role, User $actor): void
    {
        DB::transaction(function () use ($user, $role, $actor): void {
            $user->roles()->syncWithoutDetaching([$role->id => ['assigned_by' => $actor->id]]);
            $this->auditService->record('users.role_assigned', $user, $actor, ['role' => $role->name]);
        });
    }

    public function removeRole(User $user, Role $role, User $actor): void
    {
        DB::transaction(function () use ($user, $role, $actor): void {
            $user->roles()->detach($role->id);
            $this->auditService->record('users.role_removed', $user, $actor, ['role' => $role->name]);
        });
    }

    public function assignTeam(User $user, Team $team, User $actor): void
    {
        DB::transaction(function () use ($user, $team, $actor): void {
            if ($team->code === 'TUI' && ! $user->belongsToTeamCode('OCP')) {
                throw ValidationException::withMessages(['team_id' => ['TUI members must belong to OCP first.']]);
            }

            $user->teams()->syncWithoutDetaching([$team->id => ['assigned_by' => $actor->id]]);
            $this->auditService->record('users.team_assigned', $user, $actor, ['team' => $team->code]);
        });
    }

    public function removeTeam(User $user, Team $team, User $actor): void
    {
        DB::transaction(function () use ($user, $team, $actor): void {
            if ($team->code === 'OCP' && $user->belongsToTeamCode('TUI')) {
                $tui = Team::query()->where('code', 'TUI')->first();
                if ($tui !== null) {
                    $user->teams()->detach($tui->id);
                }
            }

            $user->teams()->detach($team->id);
            $this->auditService->record('users.team_removed', $user, $actor, ['team' => $team->code]);
        });
    }
}

