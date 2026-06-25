import { FormEvent, useEffect, useState } from 'react';
import { Pencil, Plus, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Role, User } from '../../types/api';

interface UserFormState {
  name: string;
  email: string;
  phoneNumber: string;
  password: string;
  accountStatus: User['account_status'];
  roleIds: string[];
}

const emptyForm: UserFormState = {
  name: '',
  email: '',
  phoneNumber: '',
  password: '',
  accountStatus: 'active',
  roleIds: [],
};

export function UsersPage() {
  const { api } = useAuth();
  const users = useApiResource<User[]>('/users');
  const roles = useApiResource<Role[]>('/admin/roles');
  const [modalMode, setModalMode] = useState<'create' | 'edit' | null>(null);
  const [editingUser, setEditingUser] = useState<User | null>(null);
  const [form, setForm] = useState<UserFormState>(emptyForm);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (modalMode === null) {
      setForm(emptyForm);
      setEditingUser(null);
      setError(null);
    }
  }, [modalMode]);

  function openCreateModal() {
    setEditingUser(null);
    setForm(emptyForm);
    setError(null);
    setModalMode('create');
  }

  function openEditModal(user: User) {
    setEditingUser(user);
    setForm({
      name: user.name,
      email: user.email,
      phoneNumber: user.phone_number ?? '',
      password: '',
      accountStatus: user.account_status,
      roleIds: user.roles?.map((role) => role.id) ?? [],
    });
    setError(null);
    setModalMode('edit');
  }

  async function submitUser(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    const payload: Record<string, unknown> = {
      name: form.name,
      email: form.email,
      phone_number: form.phoneNumber || null,
      account_status: form.accountStatus,
      role_ids: form.roleIds,
    };

    if (form.password !== '') {
      payload.password = form.password;
    }

    try {
      if (modalMode === 'edit' && editingUser !== null) {
        await api.patch(`/users/${editingUser.id}`, payload);
      } else {
        await api.post('/users', payload);
      }
      setModalMode(null);
      await users.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Gebruiker kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  function toggleRole(roleId: string) {
    setForm((current) => ({
      ...current,
      roleIds: current.roleIds.includes(roleId)
        ? current.roleIds.filter((candidate) => candidate !== roleId)
        : [...current.roleIds, roleId],
    }));
  }

  return (
    <div className="page-stack">
      <Panel
        title="Gebruikers"
        action={(
          <button className="primary-button" type="button" onClick={openCreateModal}>
            <Plus size={16} /> Gebruiker aanmaken
          </button>
        )}
      >
        <ResourceState loading={users.loading} error={users.error} empty={(users.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Naam</th><th>E-mail</th><th>Account</th><th>Push</th><th>Teams</th><th>Rollen</th><th>Actie</th></tr></thead>
            <tbody>
              {users.data?.map((user) => (
                <tr key={user.id}>
                  <td>{user.name}</td>
                  <td>{user.email}</td>
                  <td><StatusPill value={user.account_status} tone={user.account_status === 'active' ? 'good' : 'bad'} /></td>
                  <td>{user.push_enabled ? 'Actief' : 'Uit'}</td>
                  <td>{user.teams?.map((team) => team.code).join(', ') || '-'}</td>
                  <td>{user.roles?.map((role) => role.display_name).join(', ') || '-'}</td>
                  <td>
                    <button className="secondary-button" type="button" onClick={() => openEditModal(user)}>
                      <Pencil size={16} /> Aanpassen
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>

      {modalMode !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
            <header className="modal__header">
              <h2 id="user-modal-title">{modalMode === 'edit' ? 'Gebruiker aanpassen' : 'Gebruiker aanmaken'}</h2>
              <button className="icon-button" type="button" onClick={() => setModalMode(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <form className="form-grid" onSubmit={submitUser}>
              <label>
                Naam
                <input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required />
              </label>
              <label>
                E-mail
                <input type="email" value={form.email} onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))} required />
              </label>
              <label>
                Telefoonnummer
                <input value={form.phoneNumber} onChange={(event) => setForm((current) => ({ ...current, phoneNumber: event.target.value }))} />
              </label>
              <label>
                Accountstatus
                <select value={form.accountStatus} onChange={(event) => setForm((current) => ({ ...current, accountStatus: event.target.value as User['account_status'] }))}>
                  <option value="active">Actief</option>
                  <option value="suspended">Geschorst</option>
                  <option value="blocked">Geblokkeerd</option>
                </select>
              </label>
              <label className="form-grid__wide">
                Wachtwoord
                <input
                  type="password"
                  value={form.password}
                  placeholder={modalMode === 'edit' ? 'Leeg laten om niet te wijzigen' : 'Sterk wachtwoord'}
                  required={modalMode === 'create'}
                  onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))}
                />
              </label>
              <div className="form-grid__wide">
                <span className="field-label">Rollen</span>
                <ResourceState loading={roles.loading} error={roles.error} empty={(roles.data?.length ?? 0) === 0}>
                  <div className="checkbox-grid">
                    {roles.data?.map((role) => (
                      <label className="checkbox-card" key={role.id}>
                        <input
                          type="checkbox"
                          checked={form.roleIds.includes(role.id)}
                          onChange={() => toggleRole(role.id)}
                        />
                        <span>
                          <strong>{role.display_name}</strong>
                          <small>{role.description ?? role.name}</small>
                        </span>
                      </label>
                    ))}
                  </div>
                </ResourceState>
              </div>
              {error ? <p className="form-error form-grid__wide">{error}</p> : null}
              <div className="actions-row form-grid__wide">
                <button className="secondary-button" type="button" onClick={() => setModalMode(null)}>Annuleren</button>
                <button className="primary-button" type="submit" disabled={saving || roles.loading}>
                  {saving ? 'Opslaan...' : 'Opslaan'}
                </button>
              </div>
            </form>
          </section>
        </div>
      ) : null}
    </div>
  );
}
