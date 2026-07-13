<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class RoleService
{
    public function __construct(private readonly AuditService $auditService) {}

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

        $permissionIds = array_key_exists('permission_ids', $data)
            ? $this->normalizedPermissionIds($data['permission_ids'])
            : null;
        if (is_array($permissionIds)) {
            $this->assertPermissionCeiling($actor, $permissionIds);
        }
        unset($data['permission_ids']);

        return DB::transaction(function () use ($role, $data, $permissionIds, $actor): Role {
            $before = $role->only(array_keys($data));
            $role->update($data);
            if (is_array($permissionIds)) {
                $role->permissions()->sync($permissionIds);
            }
            $this->auditService->record('admin.role_updated', $role, $actor, [
                'before' => $before,
                'after' => $role->only(array_keys($data)),
                'permission_ids' => $permissionIds,
            ]);

            return $role->refresh()->load('permissions');
        });
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
}
