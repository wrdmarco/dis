import { useState } from 'react';
import { Pencil, Plus, Trash2, X } from 'lucide-react';
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
  const { api, hasPermission } = useAuth();
  const roles = useApiResource<Role[]>('/admin/roles');
  const permissions = useApiResource<Permission[]>('/admin/permissions');
  const canManageRoles = hasPermission('roles.manage');
  const [roleActionId, setRoleActionId] = useState<string | null>(null);
  const [roleForm, setRoleForm] = useState<RoleFormState>(emptyRoleForm());
  const [editingRoleId, setEditingRoleId] = useState<string | null>(null);
  const [modalMode, setModalMode] = useState<'create' | 'edit' | null>(null);
  const [deletingRole, setDeletingRole] = useState<Role | null>(null);
  const [roleError, setRoleError] = useState<string | null>(null);

  function openCreateModal() {
    if (!canManageRoles) {
      setRoleError('Je hebt geen rechten om rollen te beheren.');
      return;
    }

    setEditingRoleId(null);
    setRoleError(null);
    setRoleForm(emptyRoleForm());
    setModalMode('create');
  }

  function editRole(role: Role) {
    if (role.name === 'system-administrator') {
      setRoleError('De system administrator rol mag niet worden aangepast.');
      return;
    }

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
    setModalMode('edit');
  }

  function resetRoleForm() {
    setEditingRoleId(null);
    setRoleError(null);
    setRoleForm(emptyRoleForm());
    setModalMode(null);
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

    setRoleActionId(role.id);
    setRoleError(null);
    try {
      await api.delete(`/admin/roles/${role.id}`);
      if (editingRoleId === role.id) {
        resetRoleForm();
      }
      setDeletingRole(null);
      await roles.reload();
    } catch (error) {
      setRoleError(error instanceof ApiClientError ? error.message : 'Rol verwijderen mislukt.');
    } finally {
      setRoleActionId(null);
    }
  }

  return (
    <div className="page-stack">
      <Panel
        title="Rollen"
        action={(
          <button className="primary-button" type="button" disabled={!canManageRoles} title={canManageRoles ? 'Rol toevoegen' : 'Geen rechten om rollen te beheren'} onClick={openCreateModal}>
            <Plus size={16} /> Rol toevoegen
          </button>
        )}
      >
        {roleError ? <p className="form-error">{roleError}</p> : null}
        <div className="metadata-example">
          <strong>Standaard voor iedere ingelogde gebruiker</strong>
          <p>Iedere gebruiker kan altijd het eigen profiel bekijken en de eigen profielgegevens beheren waar dat is toegestaan. Dat is basisfunctionaliteit en staat daarom niet als aparte permissie in rollen.</p>
          <p>Rond incidenten zijn rechten bewust gescheiden: incidentregistratie gaat over gegevens en status, incidentalarmering gaat over vooraankondigen, alarmeren, opkomst en opschalen.</p>
        </div>
        <ResourceState loading={roles.loading} error={roles.error} empty={(roles.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Naam</th><th>Apps</th><th>2FA</th><th>Permissies</th><th>Gebruikers</th><th>Actie</th></tr></thead>
            <tbody>
              {roles.data?.map((role) => {
                const userCount = role.users_count ?? 0;
                const protectedRole = role.name === 'system-administrator';
                const deleteDisabled = roleActionId !== null || !canManageRoles || protectedRole || userCount > 0;
                const deleteTitle = !canManageRoles
                  ? 'Geen rechten om rollen te beheren'
                  : protectedRole
                  ? 'System administrator mag niet worden verwijderd'
                  : userCount > 0
                    ? 'Rol is nog gekoppeld aan gebruikers'
                    : 'Rol verwijderen';
                const editTitle = !canManageRoles
                  ? 'Geen rechten om rollen te beheren'
                  : protectedRole
                    ? 'System administrator mag niet worden aangepast'
                    : 'Rol bewerken';

                return (
                  <tr key={role.id}>
                    <td><strong>{role.display_name}</strong><br /><span className="mono">{role.name}</span></td>
                    <td>{role.can_use_operator_app ? 'Operator' : '-'} / {role.can_use_admin_app ? 'Admin' : '-'}</td>
                    <td>{role.requires_two_factor ? 'Verplicht' : 'Niet verplicht'}</td>
                    <td>
                      <div className="role-permission-summary">
                        {(role.permissions ?? []).slice(0, 4).map((permission) => <span key={permission.id}>{permission.display_name}</span>)}
                        {(role.permissions?.length ?? 0) > 4 ? <strong>+{(role.permissions?.length ?? 0) - 4}</strong> : null}
                      </div>
                    </td>
                    <td>{userCount}</td>
                    <td>
                      <div className="table-actions">
                        <button className="secondary-button" type="button" disabled={roleActionId !== null || !canManageRoles || protectedRole} title={editTitle} onClick={() => editRole(role)}>
                          <Pencil size={16} /> Bewerken
                        </button>
                        <button className="danger-button" type="button" disabled={deleteDisabled} title={deleteTitle} onClick={() => setDeletingRole(role)}>
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

      {modalMode !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="role-modal-title">
            <header className="modal__header">
              <h2 id="role-modal-title">{modalMode === 'edit' ? 'Rol aanpassen' : 'Rol toevoegen'}</h2>
              <button className="icon-button" type="button" onClick={resetRoleForm} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
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
              <div className="metadata-example">
                <strong>Standaard toegang</strong>
                <p>Eigen profiel bekijken en waar toegestaan eigen profielgegevens wijzigen is voor iedere ingelogde gebruiker beschikbaar. Kies hieronder alleen extra rechten voor beheer, incidenten, alarmering en systeemfuncties.</p>
                <p>Let op bij incidenten en instellingen: alarmeren staat los van incidentgegevens beheren, en push tokens beheren staat los van handmatige pushmeldingen versturen.</p>
              </div>
              <div className="permission-category-list">
                {permissionGroups(permissions.data ?? []).map((group) => (
                  <section className="permission-category" key={group.category}>
                    <header>
                      <h3>{permissionCategoryLabel(group.category)}</h3>
                      <span>{group.permissions.length} rechten</span>
                    </header>
                    {permissionCategoryDescription(group.category) ? (
                      <p className="muted-text">{permissionCategoryDescription(group.category)}</p>
                    ) : null}
                    <div className="permission-grid">
                      {group.permissions.map((permission) => (
                        <label className="checkbox-card permission-card" key={permission.id}>
                          <input type="checkbox" checked={roleForm.permissionIds.includes(permission.id)} onChange={() => toggleRolePermission(permission.id)} />
                          <span>
                            <strong>{permission.display_name}</strong>
                            <small>{permission.description ?? permission.name}</small>
                            <code>{permission.name}</code>
                          </span>
                        </label>
                      ))}
                    </div>
                  </section>
                ))}
              </div>
            </ResourceState>
            {roleError ? <p className="form-error">{roleError}</p> : null}
            <div className="actions-row">
              <button className="secondary-button" type="button" onClick={resetRoleForm} disabled={roleActionId !== null}>
                Annuleren
              </button>
              <button className="primary-button" type="button" disabled={roleActionId !== null || roleForm.name.trim() === '' || roleForm.displayName.trim() === ''} onClick={() => void saveRole()}>
                {roleActionId !== null ? 'Opslaan...' : modalMode === 'create' ? 'Rol toevoegen' : 'Rol opslaan'}
              </button>
            </div>
          </section>
        </div>
      ) : null}

      {deletingRole !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal modal--narrow" role="dialog" aria-modal="true" aria-labelledby="delete-role-title">
            <header className="modal__header">
              <h2 id="delete-role-title">Rol verwijderen</h2>
              <button className="icon-button" type="button" onClick={() => setDeletingRole(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <div className="confirm-dialog">
              <p>Weet je zeker dat je <strong>{deletingRole.display_name}</strong> wilt verwijderen?</p>
              <p className="muted-text">Dit kan alleen als er geen gebruikers gekoppeld zijn.</p>
              {roleError ? <p className="form-error">{roleError}</p> : null}
            </div>
            <div className="actions-row">
              <button className="secondary-button" type="button" onClick={() => setDeletingRole(null)} disabled={roleActionId !== null}>
                Annuleren
              </button>
              <button className="danger-button" type="button" onClick={() => void deleteRole(deletingRole)} disabled={roleActionId !== null}>
                {roleActionId !== null ? 'Verwijderen...' : 'Verwijderen'}
              </button>
            </div>
          </section>
        </div>
      ) : null}
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

function permissionGroups(permissions: Permission[]): Array<{ category: string; permissions: Permission[] }> {
  const groups = new Map<string, Permission[]>();
  for (const permission of permissions) {
    const category = permission.category || 'other';
    groups.set(category, [...(groups.get(category) ?? []), permission]);
  }

  return Array.from(groups.entries())
    .map(([category, items]) => ({
      category,
      permissions: [...items].sort((left, right) => left.display_name.localeCompare(right.display_name)),
    }))
    .sort((left, right) => permissionCategoryLabel(left.category).localeCompare(permissionCategoryLabel(right.category)));
}

function permissionCategoryLabel(category: string): string {
  switch (category) {
    case 'user_management':
      return 'Gebruikers';
    case 'address_book':
      return 'Adresboek';
    case 'role_management':
      return 'Rollen en rechten';
    case 'team_management':
      return 'Teams';
    case 'incident_management':
      return 'Incidenten';
    case 'dispatch_management':
      return 'Incidentalarmering';
    case 'status_management':
      return 'Operationele status';
    case 'asset_management':
      return 'Middelen';
    case 'certification_management':
      return 'Certificaten';
    case 'audit_log_access':
      return 'Audit';
    case 'update_management':
      return 'Updates';
    case 'push_management':
      return 'Pushmeldingen';
    case 'system_configuration':
      return 'Instellingen en systeem';
    default:
      return category;
  }
}

function permissionCategoryDescription(category: string): string | null {
  switch (category) {
    case 'incident_management':
      return 'Incidentregistratie, incidentstatus en incidentalarmering staan bij elkaar, maar blijven aparte rechten zodat iemand niet automatisch mag alarmeren omdat hij incidentgegevens mag aanpassen.';
    case 'system_configuration':
      return 'Systeeminstellingen, formulieren, branding, backups en pushbeheer zijn apart te verlenen. Push tokens intrekken is los van handmatige pushmeldingen versturen.';
    default:
      return null;
  }
}
