<?php

namespace App\Services;

use App\Events\UserAuthorizationChanged;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class RoleService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly UserService $userService,
        private readonly FcmTokenIdentityLock $tokenIdentityLock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Role
    {
        $permissionIds = $this->normalizedPermissionIds($data['permission_ids'] ?? []);
        $this->assertPermissionCeiling($actor, $permissionIds);
        unset($data['permission_ids']);

        return DB::transaction(function () use ($data, $permissionIds, $actor): Role {
            $role = Role::query()->create($data);
            $role->permissions()->sync($permissionIds);
            $this->auditService->record('admin.role_created', $role, $actor, ['permission_ids' => $permissionIds]);

            return $role->load('permissions');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data, User $actor): Role
    {
        if ($role->isSystemAdministrator()) {
            throw new ConflictHttpException('De system administrator rol mag niet worden aangepast.');
        }

        $this->assertPermissionCeiling($actor, $role->permissions()->pluck('permissions.id')->all());
        $permissionIds = array_key_exists('permission_ids', $data)
            ? $this->normalizedPermissionIds($data['permission_ids'])
            : null;
        if (is_array($permissionIds)) {
            $this->assertPermissionCeiling($actor, $permissionIds);
        }
        $appAccessDefinitionChanged = $this->appAccessDefinitionWillChange($role, $data);
        $permissionsChanged = $this->permissionsWillChange($role, $permissionIds);
        $authorizationDefinitionChanged = $appAccessDefinitionChanged || $permissionsChanged;
        $affectedUsers = $authorizationDefinitionChanged ? $role->users()->get() : collect();
        unset($data['permission_ids']);

        $operation = function () use ($role, $data, $permissionIds, $actor, $affectedUsers, $appAccessDefinitionChanged): Role {
            return DB::transaction(function () use ($role, $data, $permissionIds, $actor, $affectedUsers, $appAccessDefinitionChanged): Role {
                $previousAppAccess = $appAccessDefinitionChanged
                    ? $affectedUsers->mapWithKeys(fn (User $user): array => [
                        (string) $user->id => $this->userService->appAccessState($user),
                    ])
                    : collect();
                $before = $role->only(array_keys($data));
                $role->update($data);
                if (is_array($permissionIds)) {
                    $role->permissions()->sync($permissionIds);
                }
                $this->auditService->record('admin.role_updated', $role, $actor, [
                    'before' => $before,
                    'after' => $role->only(array_keys($data)),
                    'permission_ids' => $permissionIds,
                    'app_access_definition_changed' => $appAccessDefinitionChanged,
                ]);

                if ($appAccessDefinitionChanged) {
                    foreach ($affectedUsers as $user) {
                        $this->userService->applyAppAccessTransitionWhileUserLocked(
                            $user,
                            $actor,
                            $previousAppAccess->get((string) $user->id, ['operator' => false, 'admin' => false]),
                            'users.role_definition_app_access_changed',
                        );
                    }
                }

                return $role->refresh()->load('permissions');
            });
        };

        $updatedRole = $appAccessDefinitionChanged
            ? $this->tokenIdentityLock->synchronizedUsers(
                $affectedUsers
                    ->pluck('id')
                    ->map(static fn ($id): string => (string) $id)
                    ->values()
                    ->all(),
                $operation,
            )
            : $operation();

        if ($authorizationDefinitionChanged) {
            foreach ($affectedUsers as $user) {
                $this->dispatchAuthorizationChanged($user);
            }
        }

        return $updatedRole;
    }

    public function delete(Role $role, User $actor): void
    {
        if (! $actor->hasPermission('roles.delete')) {
            throw new AuthorizationException('Deleting roles requires an explicit permission.');
        }

        if ($role->isSystemAdministrator()) {
            throw new ConflictHttpException('De system administrator rol mag niet worden verwijderd.');
        }

        $this->assertPermissionCeiling($actor, $role->permissions()->pluck('permissions.id')->all());
        if ($role->users()->exists()) {
            throw new ConflictHttpException('Deze rol is nog gekoppeld aan gebruikers.');
        }

        DB::transaction(function () use ($role, $actor): void {
            $this->auditService->record('admin.role_deleted', $role, $actor, [
                'name' => $role->name,
                'display_name' => $role->display_name,
            ]);
            $role->permissions()->detach();
            $role->delete();
        });
    }

    /**
     * @return Collection<int, Permission>
     */
    public function assignablePermissions(User $actor): Collection
    {
        $query = Permission::query()->orderBy('category')->orderBy('name');
        if ($actor->hasRole(Role::SYSTEM_ADMINISTRATOR)) {
            return $query->get();
        }

        $permissionIds = $actor->roles()
            ->where('roles.can_use_admin_app', true)
            ->with('permissions:id')
            ->get()
            ->flatMap(fn (Role $role) => $role->permissions->pluck('id'))
            ->unique()
            ->values();

        return $query->whereIn('id', $permissionIds)->get();
    }

    /**
     * @param  list<string>  $permissionIds
     */
    private function assertPermissionCeiling(User $actor, array $permissionIds): void
    {
        if ($actor->hasRole(Role::SYSTEM_ADMINISTRATOR) || $permissionIds === []) {
            return;
        }

        $allowedIds = $this->assignablePermissions($actor)->pluck('id')->map(fn ($id): string => (string) $id);
        if (collect($permissionIds)->contains(fn (string $id): bool => ! $allowedIds->contains($id))) {
            throw new AuthorizationException('A role cannot grant permissions the actor does not hold.');
        }
    }

    /**
     * @return list<string>
     */
    private function normalizedPermissionIds(mixed $permissionIds): array
    {
        if (! is_array($permissionIds)) {
            return [];
        }

        return collect($permissionIds)
            ->filter(fn (mixed $permissionId): bool => is_string($permissionId))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function appAccessDefinitionWillChange(Role $role, array $data): bool
    {
        if (array_key_exists('can_use_operator_app', $data)
            && (bool) $data['can_use_operator_app'] !== (bool) $role->can_use_operator_app) {
            return true;
        }

        if (array_key_exists('can_use_admin_app', $data)
            && (bool) $data['can_use_admin_app'] !== (bool) $role->can_use_admin_app) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>|null  $permissionIds
     */
    private function permissionsWillChange(Role $role, ?array $permissionIds): bool
    {
        if ($permissionIds === null) {
            return false;
        }

        $currentIds = $role->permissions()
            ->pluck('permissions.id')
            ->map(static fn ($id): string => (string) $id)
            ->sort()
            ->values()
            ->all();
        $requestedIds = collect($permissionIds)->sort()->values()->all();

        return $currentIds !== $requestedIds;
    }

    private function dispatchAuthorizationChanged(User $user): void
    {
        try {
            UserAuthorizationChanged::dispatch((string) $user->id);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
