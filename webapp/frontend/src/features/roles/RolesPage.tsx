import { useState } from 'react';
import Link from 'next/link';
import { Pencil, Plus, Trash2, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { Role } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

export function RolesPage() {
  const { api, hasPermission } = useAuth();
  const roles = useApiResource<Role[]>('/admin/roles');
  const canManageRoles = hasPermission('roles.manage');
  const canDeleteRoles = hasPermission('roles.delete');
  const [roleActionId, setRoleActionId] = useState<string | null>(null);
  const [deletingRole, setDeletingRole] = useState<Role | null>(null);
  const [roleError, setRoleError] = useState<string | null>(null);

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
        action={canManageRoles ? (
          <Link className="primary-button" href="/roles/new">
            <Plus size={16} /> Rol toevoegen
          </Link>
        ) : (
          <button className="primary-button" type="button" disabled title="Geen rechten om rollen te beheren">
            <Plus size={16} /> Rol toevoegen
          </button>
        )}
      >
        {roleError ? <p className="form-error">{roleError}</p> : null}
        <div className="metadata-example">
          <strong>Standaard voor iedere ingelogde gebruiker</strong>
          <p>Iedere gebruiker kan altijd het eigen profiel bekijken en de eigen profielgegevens beheren waar dat is toegestaan. Dat is basisfunctionaliteit en staat daarom niet als aparte permissie in rollen.</p>
          <p>MFA wordt systeemwijd ingesteld bij Admin onder MFA en wachtwoordeisen. Rollen bepalen alleen toegang tot apps en functies.</p>
          <p>Rond incidenten zijn rechten bewust gescheiden: incidentregistratie gaat over gegevens en status, incidentalarmering gaat over vooraankondigen, alarmeren, opkomst en opschalen.</p>
        </div>
        <ResourceState loading={roles.loading} error={roles.error} empty={(roles.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th scope="col">Naam</th><th scope="col">Apps</th><th scope="col">Permissies</th><th scope="col">Gebruikers</th><th scope="col">Actie</th></tr></thead>
            <tbody>
              {roles.data?.map((role) => {
                const userCount = role.users_count ?? 0;
                const protectedRole = role.name === 'system-administrator';
                const editDisabled = roleActionId !== null || !canManageRoles || protectedRole;
                const deleteDisabled = roleActionId !== null || !canDeleteRoles || protectedRole || userCount > 0;
                const deleteTitle = !canDeleteRoles
                  ? 'Geen rechten om rollen te verwijderen'
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
                    <td>
                      <div className="role-permission-summary">
                        {(role.permissions ?? []).slice(0, 4).map((permission) => <span key={permission.id}>{permission.display_name}</span>)}
                        {(role.permissions?.length ?? 0) > 4 ? <strong>+{(role.permissions?.length ?? 0) - 4}</strong> : null}
                      </div>
                    </td>
                    <td>{userCount}</td>
                    <td>
                      <div className="table-actions">
                        {editDisabled ? (
                          <button className="secondary-button" type="button" disabled title={editTitle}>
                            <Pencil size={16} /> Bewerken
                          </button>
                        ) : (
                          <Link className="secondary-button" href={`/roles/${role.id}/edit`} title={editTitle}>
                            <Pencil size={16} /> Bewerken
                          </Link>
                        )}
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
