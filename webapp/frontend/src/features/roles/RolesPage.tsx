import { useState } from 'react';
import { Trash2 } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Permission, Role } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface RoleFormState {
  name: string;
  displayName: string;
  description: string;
  requiresTwoFactor: boolean;
  canUseOperatorApp: boolean;
  canUseAdminApp: boolean;
  permissionIds: string[];
}

export function RolesPage() {
  const { api } = useAuth();
  const roles = useApiResource<Role[]>('/admin/roles');
  const permissions = useApiResource<Permission[]>('/admin/permissions');
  const [roleActionId, setRoleActionId] = useState<string | null>(null);
  const [roleForm, setRoleForm] = useState<RoleFormState>(emptyRoleForm());
  const [editingRoleId, setEditingRoleId] = useState<string | null>(null);
  const [roleError, setRoleError] = useState<string | null>(null);

  function editRole(role: Role) {
    setEditingRoleId(role.id);
    setRoleError(null);
    setRoleForm({
      name: role.name,
      displayName: role.display_name,
      description: role.description ?? '',
      requiresTwoFactor: role.requires_two_factor,
      canUseOperatorApp: role.can_use_operator_app,
      canUseAdminApp: role.can_use_admin_app,
      permissionIds: role.permissions?.map((permission) => permission.id) ?? [],
    });
  }

  function resetRoleForm() {
    setEditingRoleId(null);
    setRoleError(null);
    setRoleForm(emptyRoleForm());
  }

  function toggleRolePermission(permissionId: string) {
    setRoleForm((current) => ({
      ...current,
      permissionIds: current.permissionIds.includes(permissionId)
        ? current.permissionIds.filter((id) => id !== permissionId)
        : [...current.permissionIds, permissionId],
    }));
  }

  async function saveRole() {
    setRoleActionId(editingRoleId ?? 'new');
    setRoleError(null);
    try {
      const payload = {
        name: roleForm.name.trim(),
        display_name: roleForm.displayName.trim(),
        description: roleForm.description.trim() === '' ? null : roleForm.description.trim(),
        requires_two_factor: roleForm.requiresTwoFactor,
        can_use_operator_app: roleForm.canUseOperatorApp,
        can_use_admin_app: roleForm.canUseAdminApp,
        permission_ids: roleForm.permissionIds,
      };

      if (editingRoleId === null) {
        await api.post<Role>('/admin/roles', payload);
      } else {
        await api.patch<Role>(`/admin/roles/${editingRoleId}`, payload);
      }

      resetRoleForm();
      await roles.reload();
    } catch (error) {
      setRoleError(error instanceof ApiClientError ? error.message : 'Rol opslaan mislukt.');
    } finally {
      setRoleActionId(null);
    }
  }

  async function deleteRole(role: Role) {
    if (role.name === 'system-administrator') {
      setRoleError('System administrator mag niet worden verwijderd.');
      return;
    }

    if ((role.users_count ?? 0) > 0) {
      setRoleError('Deze rol is nog gekoppeld aan gebruikers.');
      return;
    }

    if (!window.confirm(`${role.display_name} verwijderen?`)) {
      return;
    }

    setRoleActionId(role.id);
    setRoleError(null);
    try {
      await api.delete(`/admin/roles/${role.id}`);
      if (editingRoleId === role.id) {
        resetRoleForm();
      }
      await roles.reload();
    } catch (error) {
      setRoleError(error instanceof ApiClientError ? error.message : 'Rol verwijderen mislukt.');
    } finally {
      setRoleActionId(null);
    }
  }

  return (
    <div className="page-stack">
      <Panel title="Rollen">
        <div className="form-grid">
          <label>
            Rolcode
            <input value={roleForm.name} placeholder="bijv. drone-operator" onChange={(event) => setRoleForm((current) => ({ ...current, name: slugRoleName(event.target.value) }))} />
          </label>
          <label>
            Weergavenaam
            <input value={roleForm.displayName} onChange={(event) => setRoleForm((current) => ({ ...current, displayName: event.target.value }))} />
          </label>
          <label className="form-grid__wide">
            Omschrijving
            <textarea value={roleForm.description} onChange={(event) => setRoleForm((current) => ({ ...current, description: event.target.value }))} />
          </label>
          <label className="check-label">
            <input type="checkbox" checked={roleForm.requiresTwoFactor} onChange={(event) => setRoleForm((current) => ({ ...current, requiresTwoFactor: event.target.checked }))} />
            2FA verplicht
          </label>
          <label className="check-label">
            <input type="checkbox" checked={roleForm.canUseOperatorApp} onChange={(event) => setRoleForm((current) => ({ ...current, canUseOperatorApp: event.target.checked }))} />
            Operator app toestaan
          </label>
          <label className="check-label">
            <input type="checkbox" checked={roleForm.canUseAdminApp} onChange={(event) => setRoleForm((current) => ({ ...current, canUseAdminApp: event.target.checked }))} />
            Admin app toestaan
          </label>
        </div>
        <ResourceState loading={permissions.loading} error={permissions.error} empty={(permissions.data?.length ?? 0) === 0}>
          <div className="permission-grid">
            {permissions.data?.map((permission) => (
              <label className="check-label" key={permission.id}>
                <input type="checkbox" checked={roleForm.permissionIds.includes(permission.id)} onChange={() => toggleRolePermission(permission.id)} />
                {permission.display_name}
              </label>
            ))}
          </div>
        </ResourceState>
        {roleError ? <p className="form-error">{roleError}</p> : null}
        <div className="actions-row">
          <button className="primary-button" type="button" disabled={roleActionId !== null || roleForm.name.trim() === '' || roleForm.displayName.trim() === ''} onClick={() => void saveRole()}>
            {roleActionId !== null ? 'Opslaan...' : editingRoleId === null ? 'Rol maken' : 'Rol opslaan'}
          </button>
          <button className="secondary-button" type="button" onClick={resetRoleForm} disabled={roleActionId !== null}>
            Nieuw formulier
          </button>
        </div>
        <ResourceState loading={roles.loading} error={roles.error} empty={(roles.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Naam</th><th>Apps</th><th>2FA</th><th>Permissies</th><th>Gebruikers</th><th>Actie</th></tr></thead>
            <tbody>
              {roles.data?.map((role) => {
                const userCount = role.users_count ?? 0;
                const deleteDisabled = roleActionId !== null || role.name === 'system-administrator' || userCount > 0;
                const deleteTitle = role.name === 'system-administrator'
                  ? 'System administrator mag niet worden verwijderd'
                  : userCount > 0
                    ? 'Rol is nog gekoppeld aan gebruikers'
                    : 'Rol verwijderen';

                return (
                  <tr key={role.id}>
                    <td><strong>{role.display_name}</strong><br /><span className="mono">{role.name}</span></td>
                    <td>{role.can_use_operator_app ? 'Operator' : '-'} / {role.can_use_admin_app ? 'Admin' : '-'}</td>
                    <td>{role.requires_two_factor ? 'Verplicht' : 'Niet verplicht'}</td>
                    <td>{role.permissions?.length ?? 0}</td>
                    <td>{userCount}</td>
                    <td>
                      <div className="table-actions">
                        <button className="secondary-button" type="button" disabled={roleActionId !== null} onClick={() => editRole(role)}>
                          Bewerken
                        </button>
                        <button className="danger-button" type="button" disabled={deleteDisabled} title={deleteTitle} onClick={() => void deleteRole(role)}>
                          <Trash2 size={16} /> Verwijderen
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}

function emptyRoleForm(): RoleFormState {
  return {
    name: '',
    displayName: '',
    description: '',
    requiresTwoFactor: false,
    canUseOperatorApp: true,
    canUseAdminApp: false,
    permissionIds: [],
  };
}

function slugRoleName(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/-{2,}/g, '-')
    .replace(/^-+/, '');
}
