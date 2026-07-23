<?php

namespace App\Services;

use App\Mail\UserWelcomeMail;
use App\Models\AuditLog;
use App\Models\FcmToken;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Firebase\FcmClient;
use App\Support\PhoneNumber;
use App\Support\ProfileLocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UserService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly GeocodingService $geocodingService,
        private readonly FcmClient $fcmClient,
        private readonly PasswordRecoveryService $passwordRecoveryService,
        private readonly StatusService $statusService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): User
    {
        $roleIds = $data['role_ids'] ?? [];
        $teamIds = $data['team_ids'] ?? [];
        if (array_key_exists('role_ids', $data) && is_array($roleIds) && $roleIds !== []) {
            $this->assertActorCanManageRoles($actor);
            $this->assertActorCanAddSystemAdministrator($actor, null, $roleIds);
        }

        unset($data['role_ids']);
        unset($data['team_ids']);
        unset($data['send_welcome_mail']);
        $data['password'] = Str::random(48).'Aa1!';

        $data = $this->prepareUserData($data);
        $data = $this->resolveHomeCityData($data);

        $user = DB::transaction(function () use ($data, $roleIds, $teamIds, $actor): User {
            $user = User::query()->create($data);
            $this->syncRoles($user, is_array($roleIds) ? $roleIds : [], $actor);
            $this->syncTeams($user, is_array($teamIds) ? $teamIds : [], $actor);
            $this->auditService->record('users.created', $user, $actor);

            return $user->load(['roles', 'teams']);
        });

        $this->sendWelcomeMailSafely($user->refresh()->load(['roles.permissions', 'teams']), $actor);

        return $user->refresh()->load(['roles', 'teams']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $this->assertActorCanManageTarget($actor, $user);
        $originalEmail = (string) $user->email;
        $credentialsChanged = $this->credentialsWillChange($user, $data);
        $authenticationStateChanged = $credentialsChanged || $this->accessWillChange($user, $data);
        if ($credentialsChanged && ! $actor->hasPermission('users.credentials.manage')) {
            throw new AuthorizationException('Managing user credentials requires an explicit permission.');
        }

        $data = $this->prepareUserData($data, $user);
        $data = $this->resolveHomeCityData($data, $user);
        $activeMobileTokens = $authenticationStateChanged
            ? $user->fcmTokens()->where('is_active', true)->get()
            : collect();

        $updatedUser = DB::transaction(function () use ($user, $data, $actor, $credentialsChanged, $authenticationStateChanged, $originalEmail): User {
            $roleIds = $data['role_ids'] ?? null;
            $teamIds = $data['team_ids'] ?? null;
            if (array_key_exists('role_ids', $data)) {
                $this->assertActorCanManageRoles($actor);
                if (is_array($roleIds)) {
                    $this->assertActorCanAddSystemAdministrator($actor, $user, $roleIds);
                }
            }
            $this->assertSystemAdministratorRemainsActive($user, $data, is_array($roleIds) ? $roleIds : null);

            unset($data['role_ids']);
            unset($data['team_ids']);

            $auditableFields = array_values(array_diff(array_keys($data), ['password']));
            $before = $user->only($auditableFields);
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
                'after' => $user->only($auditableFields),
                'credentials_changed' => $credentialsChanged,
                'authentication_state_changed' => $authenticationStateChanged,
                'roles_synced' => is_array($roleIds),
                'teams_synced' => is_array($teamIds),
            ]);

            if ($authenticationStateChanged) {
                $auditAction = $credentialsChanged
                    ? 'users.credentials_changed_sessions_revoked'
                    : 'users.access_changed_sessions_revoked';
                $this->revokeAuthenticationState($user, $actor, $auditAction, [$originalEmail]);
            }

            return $user->refresh()->load(['roles', 'teams']);
        });

        foreach ($activeMobileTokens as $token) {
            $this->notifySessionRevoked($token);
        }

        return $updatedUser;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOwnProfile(User $user, array $data): User
    {
        $themeProvided = array_key_exists('theme', $data);
        $preferences = is_array($user->mail_preferences) ? $user->mail_preferences : [];
        if (array_key_exists('theme', $data)) {
            $preferences['ui'] = is_array($preferences['ui'] ?? null) ? $preferences['ui'] : [];
            $preferences['ui']['theme'] = $data['theme'];
            unset($data['theme']);
        }

        $profileData = [];
        foreach (['first_name', 'last_name', 'phone_number', 'home_city', 'home_region', 'home_country'] as $field) {
            if (array_key_exists($field, $data)) {
                $profileData[$field] = $data[$field];
            }
        }

        $data = $this->prepareUserData($profileData, $user);
        $data = $this->resolveHomeCityData($data, $user);
        if ($themeProvided) {
            $data['mail_preferences'] = $preferences;
        }

        return DB::transaction(function () use ($user, $data): User {
            $before = $user->only(array_keys($data));
            $user->update($data);
            $this->auditService->record('users.profile_updated', $user, $user, [
                'before' => $before,
                'after' => $user->only(array_keys($data)),
            ]);

            return $user->refresh()->load(['roles.permissions', 'teams']);
        });
    }

    public function delete(User $user, User $actor): void
    {
        if (! $actor->hasPermission('users.delete')) {
            throw new AuthorizationException('Deleting users requires an explicit permission.');
        }
        $this->assertActorCanManageTarget($actor, $user);

        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => ['Je kunt je eigen gebruiker niet verwijderen.']]);
        }

        DB::transaction(function () use ($user, $actor): void {
            $user->loadMissing('roles');

            if ($user->hasRole(Role::SYSTEM_ADMINISTRATOR)) {
                $this->assertActorIsSystemAdministrator($actor);

                if (! $this->hasOtherActiveSystemAdministrator($user)) {
                    throw ValidationException::withMessages(['user' => ['De laatste systeembeheerder kan niet worden verwijderd.']]);
                }
            }

            $user->fcmTokens()->update([
                'is_active' => false,
                'revoked_at' => now(),
            ]);
            $user->tokens()->delete();
            $user->roles()->detach();
            $user->teams()->detach();
            $this->deleteUserOwnedOperationalData($user);

            $this->auditService->record('users.deleted', $user, $actor, [
                'name' => $user->name,
                'email' => $user->email,
            ]);

            $this->preserveAuditActorName($user);
            $user->forceDelete();
        });
    }

    private function preserveAuditActorName(User $user): void
    {
        AuditLog::query()
            ->where('actor_id', $user->id)
            ->where(function ($query): void {
                $query
                    ->whereNull('actor_name')
                    ->orWhere('actor_name', '');
            })
            ->update(['actor_name' => $user->name]);
    }

    private function deleteUserOwnedOperationalData(User $user): void
    {
        $now = now();
        $userId = $user->id;

        $personalAssetIds = DB::table('asset_assignments')
            ->select('asset_id')
            ->where('user_id', $userId)
            ->whereNull('incident_id')
            ->whereNotExists(function ($query) use ($userId): void {
                $query->selectRaw('1')
                    ->from('asset_assignments as other_assignments')
                    ->whereColumn('other_assignments.asset_id', 'asset_assignments.asset_id')
                    ->where(function ($otherAssignments) use ($userId): void {
                        $otherAssignments
                            ->where('other_assignments.user_id', '<>', $userId)
                            ->orWhereNotNull('other_assignments.incident_id');
                    });
            })
            ->pluck('asset_id');

        if ($personalAssetIds->isNotEmpty()) {
            DB::table('assets')
                ->whereIn('id', $personalAssetIds)
                ->whereNull('deleted_at')
                ->update([
                    'status' => 'unavailable',
                    'deleted_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        DB::table('availability_week_patterns')->where('user_id', $userId)->delete();
        DB::table('availability_overrides')->where('user_id', $userId)->delete();
        DB::table('user_vacations')->where('user_id', $userId)->delete();
        DB::table('user_certifications')->where('user_id', $userId)->delete();
        DB::table('asset_assignments')->where('user_id', $userId)->delete();
        DB::table('location_sharing_consents')->where('user_id', $userId)->delete();
        DB::table('location_updates')->where('user_id', $userId)->delete();
        DB::table('fcm_tokens')->where('user_id', $userId)->delete();
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }

    public function assignRole(User $user, Role $role, User $actor): void
    {
        $this->assertActorCanManageRoles($actor);
        $this->assertActorCanManageTarget($actor, $user);
        $this->assertActorCanAssignRole($actor, $role);

        $activeMobileTokens = $user->fcmTokens()->where('is_active', true)->get();
        $changed = DB::transaction(function () use ($user, $role, $actor): bool {
            $changes = $user->roles()->syncWithoutDetaching([$role->id => ['assigned_by' => $actor->id]]);
            $this->auditService->record('users.role_assigned', $user, $actor, ['role' => $role->name]);

            if (($changes['attached'] ?? []) === []) {
                return false;
            }

            $this->revokeAuthenticationState($user, $actor, 'users.role_assigned_sessions_revoked');

            return true;
        });

        if ($changed) {
            foreach ($activeMobileTokens as $token) {
                $this->notifySessionRevoked($token);
            }
        }
    }

    public function removeRole(User $user, Role $role, User $actor): void
    {
        $this->assertActorCanManageRoles($actor);
        $this->assertActorCanManageTarget($actor, $user);
        $this->assertActorCanAssignRole($actor, $role);

        $activeMobileTokens = $user->fcmTokens()->where('is_active', true)->get();
        $changed = DB::transaction(function () use ($user, $role, $actor): bool {
            if ($role->isSystemAdministrator()) {
                $this->assertCanRemoveSystemAdministratorRole($user);
            }

            $detached = $user->roles()->detach($role->id);
            $this->auditService->record('users.role_removed', $user, $actor, ['role' => $role->name]);

            if ($detached === 0) {
                return false;
            }

            $this->revokeAuthenticationState($user, $actor, 'users.role_removed_sessions_revoked');

            return true;
        });

        if ($changed) {
            foreach ($activeMobileTokens as $token) {
                $this->notifySessionRevoked($token);
            }
        }
    }

    public function assignTeam(User $user, Team $team, User $actor): void
    {
        $this->assertActorCanManageTarget($actor, $user);

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
        $this->assertActorCanManageTarget($actor, $user);

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

    public function resetTwoFactor(User $user, User $actor): User
    {
        $this->assertActorCanManageTarget($actor, $user);
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => ['Je kunt je eigen MFA hier niet resetten. Gebruik je profielpagina.']]);
        }

        $activeMobileTokens = $user->fcmTokens()->where('is_active', true)->get();
        DB::transaction(function () use ($user, $actor): void {
            $user->forceFill([
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $this->revokeAuthenticationState($user, $actor, 'users.two_factor_reset_sessions_revoked');
            $this->auditService->record('users.two_factor_reset', $user, $actor);
        });

        foreach ($activeMobileTokens as $token) {
            $this->notifySessionRevoked($token);
        }

        return $user->refresh()->load(['roles', 'teams']);
    }

    public function resetLoginLock(User $user, User $actor): User
    {
        $this->assertActorCanManageTarget($actor, $user);
        $user->forceFill([
            'failed_login_attempts' => 0,
            'login_locked_until' => null,
        ])->save();

        $this->auditService->record('users.login_lock_reset', $user, $actor);

        return $user->refresh()->load(['roles', 'teams']);
    }

    /**
     * @return array{access_tokens_revoked: int, web_sessions_revoked: int, mobile_tokens_revoked: int, pairing_codes_revoked: int}
     */
    public function revokeSessions(User $user, User $actor): array
    {
        $this->assertActorCanManageTarget($actor, $user);
        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => ['Je kunt je eigen sessies niet via gebruikersbeheer intrekken. Log zelf uit via je profiel.']]);
        }

        $activeMobileTokens = $user->fcmTokens()
            ->where('is_active', true)
            ->get();

        $result = DB::transaction(fn (): array => $this->revokeAuthenticationState($user, $actor, 'users.sessions_revoked'));

        foreach ($activeMobileTokens as $token) {
            $this->notifySessionRevoked($token);
        }

        return $result;
    }

    public function resendWelcomeMail(User $user, User $actor): User
    {
        $this->assertActorCanManageTarget($actor, $user);
        if ($user->last_login_at !== null) {
            throw ValidationException::withMessages(['user' => ['Deze gebruiker is al geactiveerd.']]);
        }

        if ($user->account_status !== 'active') {
            throw ValidationException::withMessages(['user' => ['Alleen actieve gebruikers kunnen een uitnodiging ontvangen.']]);
        }

        $this->sendWelcomeMailOrFail($user->refresh()->load(['roles.permissions', 'teams']), $actor);

        return $user->refresh()->load(['roles', 'teams']);
    }

    public function sendPasswordRecovery(User $user, User $actor): User
    {
        $this->assertActorCanManageTarget($actor, $user);
        if ($user->account_status !== 'active') {
            throw ValidationException::withMessages(['user' => ['Alleen actieve gebruikers kunnen een herstelmail ontvangen.']]);
        }

        try {
            $this->runIgnoringTempnamFallbackWarning(
                fn (): mixed => $this->passwordRecoveryService->deliver($user),
            );
        } catch (Throwable $exception) {
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            report($exception);
            throw ValidationException::withMessages(['mail' => ['Wachtwoordherstelmail kon niet worden verstuurd.']]);
        }

        $this->auditService->record('users.password_recovery_sent', $user, $actor);

        return $user->refresh()->load(['roles', 'teams']);
    }

    private function notifySessionRevoked(FcmToken $token): bool
    {
        if (! $token->is_active) {
            return false;
        }

        try {
            $this->fcmClient->send(
                $token,
                'Sessie ingetrokken',
                'Je bent uitgelogd door een beheerder.',
                ['type' => 'session_revoked'],
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return true;
    }

    /**
     * @param  array<int, string>  $roleIds
     */
    private function syncRoles(User $user, array $roleIds, User $actor): void
    {
        $this->assertSystemAdministratorRemainsActive($user, [], $roleIds);
        $user->roles()
            ->with('permissions')
            ->get()
            ->each(fn (Role $role) => $this->assertActorCanAssignRole($actor, $role));
        Role::query()
            ->whereIn('id', array_values(array_unique($roleIds)))
            ->with('permissions')
            ->get()
            ->each(fn (Role $role) => $this->assertActorCanAssignRole($actor, $role));

        $syncPayload = [];
        foreach (array_values(array_unique($roleIds)) as $roleId) {
            $syncPayload[$roleId] = ['assigned_by' => $actor->id];
        }

        $user->roles()->sync($syncPayload);
    }

    /**
     * @param  array<int, string>  $teamIds
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
            ->whereHas('roles', fn ($roles) => $roles->where('name', Role::SYSTEM_ADMINISTRATOR))
            ->exists();
    }

    private function assertActorCanManageRoles(User $actor): void
    {
        if (! $actor->hasPermission('roles.manage')) {
            throw new AuthorizationException('Managing roles requires an explicit permission.');
        }
    }

    private function assertActorCanManageTarget(User $actor, User $target): void
    {
        if ($target->hasRole(Role::SYSTEM_ADMINISTRATOR) && ! $actor->hasRole(Role::SYSTEM_ADMINISTRATOR)) {
            throw new AuthorizationException('Only a system administrator can modify another system administrator.');
        }
    }

    private function assertActorCanAssignRole(User $actor, Role $role): void
    {
        if ($actor->hasRole(Role::SYSTEM_ADMINISTRATOR)) {
            return;
        }

        if ($role->isSystemAdministrator()) {
            throw new AuthorizationException('Only a system administrator can assign the system administrator role.');
        }

        $role->loadMissing('permissions');
        $missingPermission = $role->permissions->first(fn ($permission): bool => ! $actor->hasPermission($permission->name));
        if ($missingPermission !== null) {
            throw new AuthorizationException('A role cannot grant permissions the actor does not hold.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function credentialsWillChange(User $user, array $data): bool
    {
        $emailChanged = array_key_exists('email', $data)
            && mb_strtolower(trim((string) $data['email'])) !== mb_strtolower((string) $user->email);
        $passwordChanged = array_key_exists('password', $data)
            && is_string($data['password'])
            && $data['password'] !== '';

        return $emailChanged || $passwordChanged;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function accessWillChange(User $user, array $data): bool
    {
        if (array_key_exists('account_status', $data)
            && (string) $data['account_status'] !== (string) $user->account_status) {
            return true;
        }

        if (! array_key_exists('role_ids', $data) || ! is_array($data['role_ids'])) {
            return false;
        }

        $currentRoleIds = $user->roles()->pluck('roles.id')->map(static fn ($id): string => (string) $id)->sort()->values()->all();
        $requestedRoleIds = collect($data['role_ids'])
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $currentRoleIds !== $requestedRoleIds;
    }

    /**
     * @param  list<string>  $additionalPasswordResetEmails
     * @return array{access_tokens_revoked: int, web_sessions_revoked: int, mobile_tokens_revoked: int, pairing_codes_revoked: int}
     */
    public function revokeAuthenticationState(
        User $user,
        User $actor,
        string $auditAction,
        array $additionalPasswordResetEmails = [],
    ): array {
        $result = [
            'access_tokens_revoked' => $user->tokens()->count(),
            'web_sessions_revoked' => DB::table('sessions')->where('user_id', $user->id)->count(),
            'mobile_tokens_revoked' => $user->fcmTokens()->where('is_active', true)->count(),
            'pairing_codes_revoked' => DB::table('mobile_pairing_codes')
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->count(),
        ];

        $user->tokens()->delete();
        $user->increment('auth_session_version');
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('mobile_pairing_codes')
            ->where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->delete();
        $passwordResetEmails = collect([$user->email, ...$additionalPasswordResetEmails])
            ->filter(static fn (mixed $email): bool => is_string($email) && trim($email) !== '')
            ->unique()
            ->values()
            ->all();
        DB::table('password_reset_tokens')->whereIn('email', $passwordResetEmails)->delete();
        $user->fcmTokens()
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        if (! $user->fcmTokens()->where('is_active', true)->exists()) {
            $user->forceFill(['push_enabled' => false])->save();
            $this->statusService->enforcePushUnavailable($user->refresh());
        }

        $this->auditService->record($auditAction, $user, $actor, $result);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareUserData(array $data, ?User $user = null): array
    {
        foreach (['first_name', 'last_name', 'home_city', 'home_region'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) ($data[$field] ?? ''));
                $data[$field] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('home_country', $data)) {
            $country = strtoupper(trim((string) ($data['home_country'] ?? '')));
            $data['home_country'] = $country === '' ? null : $country;
        }

        $country = $data['home_country'] ?? $user?->home_country;
        if (array_key_exists('home_region', $data)) {
            $this->assertRegionMatchesCountry($data['home_region'] ?? null, $country);
        }

        if (array_key_exists('phone_number', $data)) {
            $data['phone_number'] = PhoneNumber::normalize($data['phone_number'] ?? null, is_string($country) ? $country : null);
        }

        if (array_key_exists('name', $data) && (! array_key_exists('first_name', $data) || ! array_key_exists('last_name', $data))) {
            [$firstName, $lastName] = $this->splitDisplayName((string) ($data['name'] ?? ''));
            if (! array_key_exists('first_name', $data)) {
                $data['first_name'] = $firstName;
            }
            if (! array_key_exists('last_name', $data)) {
                $data['last_name'] = $lastName;
            }
        }

        if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data)) {
            $firstName = trim((string) ($data['first_name'] ?? $user?->first_name ?? ''));
            $lastName = trim((string) ($data['last_name'] ?? $user?->last_name ?? ''));
            $displayName = trim($firstName.' '.$lastName);
            if ($displayName !== '') {
                $data['name'] = $displayName;
            }
        }

        return $data;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitDisplayName(string $name): array
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($normalized === '') {
            return [null, null];
        }

        $parts = explode(' ', $normalized, 2);

        return [
            $parts[0] !== '' ? $parts[0] : null,
            isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null,
        ];
    }

    private function assertRegionMatchesCountry(mixed $region, mixed $country): void
    {
        $normalizedRegion = trim((string) ($region ?? ''));
        $regions = ProfileLocation::regionsFor(is_string($country) ? $country : null);
        if ($normalizedRegion === '' || $regions === []) {
            return;
        }

        if (! in_array($normalizedRegion, $regions, true)) {
            throw ValidationException::withMessages(['home_region' => ['Kies een geldige provincie of regio voor het gekozen land.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveHomeCityData(array $data, ?User $user = null): array
    {
        if (! array_key_exists('home_city', $data)) {
            return $data;
        }

        $homeCity = trim((string) ($data['home_city'] ?? ''));
        if ($homeCity === '') {
            $data['home_city'] = null;
            $data['home_latitude'] = null;
            $data['home_longitude'] = null;
            $data['home_geocoded_at'] = null;
            $data['home_geocode_source'] = null;

            return $data;
        }

        $data['home_city'] = $homeCity;
        $homeRegion = trim((string) ($data['home_region'] ?? $user?->home_region ?? ''));
        $homeCountry = strtoupper(trim((string) ($data['home_country'] ?? $user?->home_country ?? '')));
        if (
            $user !== null
            && trim((string) $user->home_city) === $homeCity
            && trim((string) $user->home_region) === $homeRegion
            && strtoupper(trim((string) $user->home_country)) === $homeCountry
        ) {
            return $data;
        }

        $coordinates = $this->geocodingService->coordinatesFor($this->homeLocationLabel($homeCity, $homeRegion, $homeCountry));
        $data['home_latitude'] = $coordinates === null ? null : number_format((float) $coordinates['latitude'], 2, '.', '');
        $data['home_longitude'] = $coordinates === null ? null : number_format((float) $coordinates['longitude'], 2, '.', '');
        $data['home_geocoded_at'] = $coordinates === null ? null : now();
        $data['home_geocode_source'] = $coordinates === null ? null : (string) config('dis.geocoding.provider', 'nominatim');

        return $data;
    }

    private function homeLocationLabel(string $city, string $region, string $country): string
    {
        return collect([
            $city,
            $region,
            ProfileLocation::countryName($country),
        ])
            ->filter(fn (?string $part): bool => trim((string) $part) !== '')
            ->implode(', ');
    }

    /**
     * @param  array<int, string>  $roleIds
     */
    private function assertActorCanAddSystemAdministrator(User $actor, ?User $user, array $roleIds): void
    {
        if (! $this->roleIdsContainSystemAdministrator($roleIds)) {
            return;
        }

        if ($user?->hasRole(Role::SYSTEM_ADMINISTRATOR) === true) {
            return;
        }

        $this->assertActorIsSystemAdministrator($actor);
    }

    private function assertActorIsSystemAdministrator(User $actor): void
    {
        if (! $actor->hasRole(Role::SYSTEM_ADMINISTRATOR)) {
            throw ValidationException::withMessages(['role_ids' => ['Alleen een system admin mag de system admin rol toekennen.']]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>|null  $roleIds
     */
    private function assertSystemAdministratorRemainsActive(User $user, array $data, ?array $roleIds): void
    {
        $user->loadMissing('roles');
        if (! $user->hasRole(Role::SYSTEM_ADMINISTRATOR)) {
            return;
        }

        if ($this->hasOtherActiveSystemAdministrator($user)) {
            return;
        }

        if (($data['account_status'] ?? $user->account_status) !== 'active') {
            throw ValidationException::withMessages(['user' => ['De laatste systeembeheerder moet actief blijven.']]);
        }

        if (is_array($roleIds) && ! $this->roleIdsContainSystemAdministrator($roleIds)) {
            throw ValidationException::withMessages(['role_ids' => ['De laatste systeembeheerder kan zijn system administrator rol niet verliezen.']]);
        }
    }

    private function assertCanRemoveSystemAdministratorRole(User $user): void
    {
        $user->loadMissing('roles');
        if ($user->hasRole(Role::SYSTEM_ADMINISTRATOR) && ! $this->hasOtherActiveSystemAdministrator($user)) {
            throw ValidationException::withMessages(['role' => ['De laatste systeembeheerder kan zijn system administrator rol niet verliezen.']]);
        }
    }

    /**
     * @param  array<int, string>  $roleIds
     */
    private function roleIdsContainSystemAdministrator(array $roleIds): bool
    {
        return Role::query()
            ->whereIn('id', array_values(array_unique($roleIds)))
            ->where('name', Role::SYSTEM_ADMINISTRATOR)
            ->exists();
    }

    private function sendWelcomeMail(User $user, User $actor): void
    {
        $token = Password::broker()->createToken($user);
        $publicUrl = rtrim(SystemSetting::string('app.public_url', config('app.url', '')) ?? '', '/');
        $registrationUrl = $publicUrl.'/register#email='.rawurlencode($user->email).'&token='.rawurlencode($token);
        $adminAppAllowed = $user->canUseAdminApp();

        $this->runIgnoringTempnamFallbackWarning(
            fn (): mixed => Mail::to($user->email)->send(new UserWelcomeMail($user, $registrationUrl, $adminAppAllowed)),
        );
        $this->auditService->record('users.welcome_mail_sent', $user, $actor, ['admin_app_allowed' => $adminAppAllowed]);
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function runIgnoringTempnamFallbackWarning(callable $callback): mixed
    {
        set_error_handler(function (int $severity, string $message): bool {
            if (str_contains($message, 'tempnam(): file created in the system')) {
                return true;
            }

            return false;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    private function sendWelcomeMailSafely(User $user, User $actor): void
    {
        try {
            $this->sendWelcomeMail($user, $actor);
        } catch (Throwable $exception) {
            $this->recordWelcomeMailFailure($user, $actor, $exception);
        }
    }

    private function sendWelcomeMailOrFail(User $user, User $actor): void
    {
        try {
            $this->sendWelcomeMail($user, $actor);
        } catch (Throwable $exception) {
            $this->recordWelcomeMailFailure($user, $actor, $exception);

            throw ValidationException::withMessages([
                'mail' => ['Uitnodiging kon niet worden verstuurd.'],
            ]);
        }
    }

    private function recordWelcomeMailFailure(User $user, User $actor, Throwable $exception): void
    {
        try {
            Log::warning('Welcome mail could not be sent.', [
                'user_id' => $user->id,
                'actor_id' => $actor->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        } catch (Throwable) {
            // Logging must never block the primary user flow.
        }

        try {
            $this->auditService->record('users.welcome_mail_failed', $user, $actor, [
                'exception_type' => $exception::class,
            ]);
        } catch (Throwable) {
            // Audit is best-effort here because the account already exists.
        }
    }
}
