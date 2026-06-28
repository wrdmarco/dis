<?php

namespace App\Services;

use App\Mail\UserWelcomeMail;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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
            $roleIds = $data['role_ids'] ?? [];
            $teamIds = $data['team_ids'] ?? [];
            $sendWelcomeMail = (bool) ($data['send_welcome_mail'] ?? false);
            unset($data['role_ids']);
            unset($data['team_ids']);
            unset($data['send_welcome_mail']);

            if ($sendWelcomeMail && empty($data['password'])) {
                $data['password'] = Str::random(32).'Aa1!';
            }

            $user = User::query()->create($data);
            $this->syncRoles($user, is_array($roleIds) ? $roleIds : [], $actor);
            $this->syncTeams($user, is_array($teamIds) ? $teamIds : [], $actor);
            $this->auditService->record('users.created', $user, $actor);

            if ($sendWelcomeMail) {
                $this->sendWelcomeMail($user->refresh()->load(['roles.permissions', 'teams']), $actor);
            }

            return $user->load(['roles', 'teams']);
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $roleIds = $data['role_ids'] ?? null;
            $teamIds = $data['team_ids'] ?? null;
            unset($data['role_ids']);
            unset($data['team_ids']);

            $before = $user->only(array_keys($data));
            if ($data !== []) {
                $user->update($data);
            }

            if (is_array($roleIds)) {
                $this->syncRoles($user, $roleIds, $actor);
            }

            if (is_array($teamIds)) {
                $this->syncTeams($user, $teamIds, $actor);
            }

            $this->auditService->record('users.updated', $user, $actor, [
                'before' => $before,
                'after' => $user->only(array_keys($data)),
                'roles_synced' => is_array($roleIds),
                'teams_synced' => is_array($teamIds),
            ]);

            return $user->refresh()->load(['roles', 'teams']);
        });
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => ['Je kunt je eigen gebruiker niet verwijderen.']]);
        }

        DB::transaction(function () use ($user, $actor): void {
            $user->loadMissing('roles');

            if ($user->hasRole('system-administrator') && ! $this->hasOtherActiveSystemAdministrator($user)) {
                throw ValidationException::withMessages(['user' => ['De laatste systeembeheerder kan niet worden verwijderd.']]);
            }

            $user->fcmTokens()->update([
                'is_active' => false,
                'revoked_at' => now(),
            ]);
            $user->tokens()->delete();
            $user->update([
                'account_status' => 'blocked',
                'push_enabled' => false,
            ]);

            $this->auditService->record('users.deleted', $user, $actor, [
                'name' => $user->name,
                'email' => $user->email,
            ]);

            $user->delete();
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

    /**
     * @param array<int, string> $roleIds
     */
    private function syncRoles(User $user, array $roleIds, User $actor): void
    {
        $syncPayload = [];
        foreach (array_values(array_unique($roleIds)) as $roleId) {
            $syncPayload[$roleId] = ['assigned_by' => $actor->id];
        }

        $user->roles()->sync($syncPayload);
    }

    /**
     * @param array<int, string> $teamIds
     */
    private function syncTeams(User $user, array $teamIds, User $actor): void
    {
        $uniqueTeamIds = array_values(array_unique($teamIds));
        $teams = Team::query()->whereIn('id', $uniqueTeamIds)->get(['id', 'code']);
        $codes = $teams->pluck('code')->all();

        if (in_array('TUI', $codes, true) && ! in_array('OCP', $codes, true)) {
            throw ValidationException::withMessages(['team_ids' => ['TUI members must belong to OCP first.']]);
        }

        $syncPayload = [];
        foreach ($uniqueTeamIds as $teamId) {
            $syncPayload[$teamId] = ['assigned_by' => $actor->id];
        }

        $user->teams()->sync($syncPayload);
    }

    private function hasOtherActiveSystemAdministrator(User $user): bool
    {
        return User::query()
            ->whereKeyNot($user->getKey())
            ->where('account_status', 'active')
            ->whereHas('roles', fn ($roles) => $roles->where('name', 'system-administrator'))
            ->exists();
    }

    private function sendWelcomeMail(User $user, User $actor): void
    {
        $token = Password::broker()->createToken($user);
        $publicUrl = rtrim(SystemSetting::string('app.public_url', config('app.url', '')) ?? '', '/');
        $registrationUrl = $publicUrl.'/register?email='.rawurlencode($user->email).'&token='.rawurlencode($token);
        $adminAppAllowed = $user->canUseAdminApp();

        Mail::to($user->email)->send(new UserWelcomeMail($user, $registrationUrl, $adminAppAllowed));
        $this->auditService->record('users.welcome_mail_sent', $user, $actor, ['admin_app_allowed' => $adminAppAllowed]);
    }
}
