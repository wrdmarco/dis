import { FormEvent, useEffect, useState } from 'react';
import { Eye, KeyRound, Mail, Plus, Trash2, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { assetDisplayLabel } from '../../lib/assetLabels';
import { formatDateOnly, formatDateTime, todayAmsterdamDateInputValue } from '../../lib/dateTime';
import { droneTypeLabel } from '../../lib/droneTypes';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Asset, Certification, Role, Team, User, UserVacation } from '../../types/api';

interface UserFormState {
  name: string;
  email: string;
  phoneNumber: string;
  homeCity: string;
  password: string;
  sendWelcomeMail: boolean;
  accountStatus: User['account_status'];
  maxOperatorDevices: string;
  roleIds: string[];
  teamIds: string[];
}

const emptyForm: UserFormState = {
  name: '',
  email: '',
  phoneNumber: '',
  homeCity: '',
  password: '',
  sendWelcomeMail: true,
  accountStatus: 'active',
  maxOperatorDevices: '1',
  roleIds: [],
  teamIds: [],
};

export function UsersPage() {
  const { api, hasPermission, user: currentUser } = useAuth();
  const canManageUsers = hasPermission('users.manage');
  const canManageRoles = hasPermission('roles.manage');
  const canManageTeams = hasPermission('teams.manage');
  const canManageAssets = hasPermission('assets.manage');
  const canManageCertifications = hasPermission('certifications.manage');
  const canManageVacations = canManageUsers;
  const users = useApiResource<User[]>('/users');
  const roles = useApiResource<Role[]>('/admin/roles', canManageRoles);
  const teams = useApiResource<Team[]>('/admin/teams', canManageTeams);
  const assets = useApiResource<Asset[]>('/assets', canManageAssets);
  const certifications = useApiResource<Certification[]>('/certifications', canManageCertifications);
  const [modalMode, setModalMode] = useState<'create' | 'edit' | null>(null);
  const [editingUser, setEditingUser] = useState<User | null>(null);
  const [deletingUser, setDeletingUser] = useState<User | null>(null);
  const [userDetail, setUserDetail] = useState<User | null>(null);
  const [userDetailLoading, setUserDetailLoading] = useState(false);
  const [userDetailError, setUserDetailError] = useState<string | null>(null);
  const [form, setForm] = useState<UserFormState>(emptyForm);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [resettingMfa, setResettingMfa] = useState(false);
  const [resettingLoginLock, setResettingLoginLock] = useState(false);
  const [resendingInvitation, setResendingInvitation] = useState(false);
  const [invitationMessage, setInvitationMessage] = useState<string | null>(null);
  const isSystemAdministrator = currentUser?.roles?.some((role) => role.name === 'system-administrator') ?? false;
  const assignableRoles = (roles.data ?? []).filter((role) => isSystemAdministrator || role.name !== 'system-administrator');
  const activeSystemAdministratorCount = users.data?.filter((user) => user.account_status === 'active' && hasSystemAdministratorRole(user)).length ?? 0;
  const canDeleteEditingUser = editingUser !== null
    && canManageUsers
    && currentUser?.id !== editingUser.id
    && (
      !hasSystemAdministratorRole(editingUser)
      || (isSystemAdministrator && activeSystemAdministratorCount > 1)
    );

  useEffect(() => {
    if (modalMode === null) {
      setForm(emptyForm);
      setEditingUser(null);
      setUserDetail(null);
      setUserDetailLoading(false);
      setUserDetailError(null);
      setError(null);
      setInvitationMessage(null);
      setResendingInvitation(false);
      setResettingLoginLock(false);
    }
  }, [modalMode]);

  useEffect(() => {
    if (deletingUser === null) {
      setDeleting(false);
    }
  }, [deletingUser]);

  function openCreateModal() {
    setEditingUser(null);
    setUserDetail(null);
    setUserDetailError(null);
    setForm(emptyForm);
    setError(null);
    setModalMode('create');
  }

  function openEditModal(user: User) {
    setEditingUser(user);
    setUserDetail(user);
    setUserDetailError(null);
    setForm({
      name: user.name,
      email: user.email,
      phoneNumber: user.phone_number ?? '',
      homeCity: user.home_city ?? '',
      password: '',
      sendWelcomeMail: false,
      accountStatus: user.account_status,
      maxOperatorDevices: String(user.max_operator_devices ?? 1),
      roleIds: user.roles?.map((role) => role.id) ?? [],
      teamIds: user.teams?.map((team) => team.id) ?? [],
    });
    setError(null);
    setInvitationMessage(null);
    setModalMode('edit');
    void loadUserDetail(user.id);
  }

  async function loadUserDetail(userId: string) {
    setUserDetailLoading(true);
    setUserDetailError(null);

    try {
      const response = await api.get<User>(`/users/${userId}`);
      setUserDetail(response.data);
    } catch (err) {
      setUserDetailError(err instanceof ApiClientError ? err.message : 'Gebruikersdetails konden niet worden geladen.');
    } finally {
      setUserDetailLoading(false);
    }
  }

  async function reloadUserDetail() {
    if (editingUser !== null) {
      await loadUserDetail(editingUser.id);
      await users.reload();
      await assets.reload();
    }
  }

  async function resetUserMfa() {
    if (editingUser === null) {
      return;
    }

    setResettingMfa(true);
    setError(null);
    try {
      const response = await api.post<User>(`/users/${editingUser.id}/2fa/reset`);
      setEditingUser(response.data);
      setUserDetail(response.data);
      await users.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'MFA resetten mislukt.');
    } finally {
      setResettingMfa(false);
    }
  }

  async function resetLoginLock() {
    if (editingUser === null) {
      return;
    }

    setResettingLoginLock(true);
    setError(null);
    try {
      const response = await api.post<User>(`/users/${editingUser.id}/login-lock/reset`);
      setEditingUser(response.data);
      setUserDetail((current) => current === null ? response.data : { ...current, ...response.data });
      await users.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Loginvergrendeling resetten mislukt.');
    } finally {
      setResettingLoginLock(false);
    }
  }

  async function resendInvitation() {
    if (editingUser === null) {
      return;
    }

    setResendingInvitation(true);
    setError(null);
    setInvitationMessage(null);
    try {
      const response = await api.post<User>(`/users/${editingUser.id}/invitation/resend`);
      setEditingUser(response.data);
      setUserDetail((current) => current === null ? response.data : { ...current, ...response.data });
      setInvitationMessage('Uitnodiging is opnieuw verstuurd.');
      await users.reload();
    } catch (err) {
      setInvitationMessage(err instanceof ApiClientError ? err.message : 'Uitnodiging opnieuw versturen mislukt.');
    } finally {
      setResendingInvitation(false);
    }
  }

  async function submitUser(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    const payload: Record<string, unknown> = {
      name: form.name,
      email: form.email,
      phone_number: form.phoneNumber || null,
      home_city: form.homeCity.trim() === '' ? null : form.homeCity.trim(),
      account_status: form.accountStatus,
      max_operator_devices: Math.max(1, Number(form.maxOperatorDevices || 1)),
      role_ids: form.roleIds,
      team_ids: form.teamIds,
    };

    if (form.password !== '') {
      payload.password = form.password;
    }

    if (modalMode === 'create') {
      payload.send_welcome_mail = form.sendWelcomeMail;
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

  async function deleteUser() {
    if (deletingUser === null) {
      return;
    }

    setDeleting(true);
    setError(null);

    try {
      await api.delete(`/users/${deletingUser.id}`);
      if (editingUser?.id === deletingUser.id) {
        setModalMode(null);
      }
      setDeletingUser(null);
      await users.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Gebruiker kon niet worden verwijderd.');
    } finally {
      setDeleting(false);
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

  function toggleTeam(teamId: string) {
    setForm((current) => ({
      ...current,
      teamIds: current.teamIds.includes(teamId)
        ? current.teamIds.filter((candidate) => candidate !== teamId)
        : [...current.teamIds, teamId],
    }));
  }

  return (
    <div className="page-stack">
      <Panel
        title="Gebruikers"
        action={canManageUsers ? (
          <button className="primary-button" type="button" onClick={openCreateModal}>
            <Plus size={16} /> Gebruiker aanmaken
          </button>
        ) : null}
      >
        <ResourceState loading={users.loading} error={users.error} empty={(users.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Naam</th><th>E-mail</th><th>Woonplaats</th><th>Account</th><th>Online</th><th>Push</th><th>Teams</th><th>Rollen</th><th>Actie</th></tr></thead>
            <tbody>
              {users.data?.map((user) => {
                const onlineDevices = user.fcm_tokens?.filter((token) => token.client_type !== 'admin' && token.is_online).length ?? 0;
                const activeDevices = user.fcm_tokens?.filter((token) => token.client_type !== 'admin' && token.is_active).length ?? 0;

                return (
                  <tr key={user.id}>
                    <td>{user.name}</td>
                    <td>{user.email}</td>
                    <td>{user.home_city ?? '-'}</td>
                    <td><StatusPill value={user.account_status} tone={user.account_status === 'active' ? 'good' : 'bad'} /></td>
                    <td><StatusPill value={onlineDevices > 0 ? `Online (${onlineDevices})` : 'Offline'} tone={onlineDevices > 0 ? 'good' : 'neutral'} /></td>
                    <td>{user.push_enabled ? `Actief (${activeDevices}/${user.max_operator_devices ?? 1})` : 'Uit'}</td>
                    <td>{user.teams?.map((team) => team.code).join(', ') || '-'}</td>
                    <td>{user.roles?.map((role) => role.display_name).join(', ') || '-'}</td>
                    <td>
                      <div className="table-actions">
                        <button className="secondary-button" type="button" onClick={() => openEditModal(user)}>
                          <Eye size={16} /> Details
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
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
            <header className="modal__header">
              <h2 id="user-modal-title">{modalMode === 'edit' ? 'Gebruiker aanpassen' : 'Gebruiker aanmaken'}</h2>
              <button className="icon-button" type="button" onClick={() => setModalMode(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <form className="form-grid" onSubmit={canManageUsers ? submitUser : (event) => event.preventDefault()}>
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
                Woonplaats
                <input value={form.homeCity} maxLength={120} onChange={(event) => setForm((current) => ({ ...current, homeCity: event.target.value }))} />
                <small>Globale plaats voor geschatte ETA-ringen, geen exact adres.</small>
              </label>
              <label>
                Accountstatus
                <select value={form.accountStatus} onChange={(event) => setForm((current) => ({ ...current, accountStatus: event.target.value as User['account_status'] }))}>
                  <option value="active">Actief</option>
                  <option value="suspended">Geschorst</option>
                  <option value="blocked">Geblokkeerd</option>
                </select>
              </label>
              <label>
                Max operator-devices
                <input
                  type="number"
                  min="1"
                  max="20"
                  value={form.maxOperatorDevices}
                  onChange={(event) => setForm((current) => ({ ...current, maxOperatorDevices: event.target.value }))}
                />
                <small>Standaard 1. Admin-apps tellen niet mee.</small>
              </label>
              <label className="form-grid__wide">
                Wachtwoord
                <input
                  type="password"
                  value={form.password}
                  placeholder={modalMode === 'edit' ? 'Leeg laten om niet te wijzigen' : form.sendWelcomeMail ? 'Gebruiker stelt wachtwoord zelf in' : 'Volgens wachtwoordbeleid'}
                  required={modalMode === 'create' && !form.sendWelcomeMail}
                  autoComplete="new-password"
                  onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))}
                />
                <small>Moet voldoen aan de ingestelde wachtwoordeisen.</small>
              </label>
              {modalMode === 'create' ? (
                <label className="check-label form-grid__wide">
                  <input
                    type="checkbox"
                    checked={form.sendWelcomeMail}
                    onChange={(event) => setForm((current) => ({ ...current, sendWelcomeMail: event.target.checked }))}
                  />
                  Welkomstmail sturen en registratie laten afronden
                </label>
              ) : null}
              {canManageRoles ? <div className="form-grid__wide">
                <span className="field-label">Rollen</span>
                <ResourceState loading={roles.loading} error={roles.error} empty={(roles.data?.length ?? 0) === 0}>
                  <div className="checkbox-grid">
                    {assignableRoles.map((role) => (
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
              </div> : null}
              {canManageTeams ? <div className="form-grid__wide">
                <span className="field-label">Teams</span>
                <ResourceState loading={teams.loading} error={teams.error} empty={(teams.data?.length ?? 0) === 0}>
                  <div className="checkbox-grid">
                    {teams.data?.map((team) => (
                      <label className="checkbox-card" key={team.id}>
                        <input
                          type="checkbox"
                          checked={form.teamIds.includes(team.id)}
                          onChange={() => toggleTeam(team.id)}
                        />
                        <span>
                          <strong>{team.code} - {team.name}</strong>
                          <small>{team.type}</small>
                        </span>
                      </label>
                    ))}
                  </div>
                </ResourceState>
              </div> : null}
              {modalMode === 'edit' ? (
                <UserOperationalDetails
                  user={userDetail}
                  loading={userDetailLoading}
                  error={userDetailError}
                  assets={canManageAssets ? assets.data ?? [] : []}
                  assetsLoading={canManageAssets && assets.loading}
                  assetsError={canManageAssets ? assets.error : null}
                  certifications={canManageCertifications ? certifications.data ?? [] : []}
                  certificationsLoading={canManageCertifications && certifications.loading}
                  certificationsError={canManageCertifications ? certifications.error : null}
                  canManageAssets={canManageAssets}
                  canManageCertifications={canManageCertifications}
                  canManageVacations={canManageVacations}
                  onChanged={reloadUserDetail}
                />
              ) : null}
              {modalMode === 'edit' && editingUser !== null && canManageUsers ? (
                <div className="form-grid__wide stacked-section">
                  <span className="field-label">Uitnodiging</span>
                  <dl className="definition-grid">
                    <dt>Activatie</dt>
                    <dd>{(userDetail ?? editingUser).last_login_at ? 'Geactiveerd' : 'Nog niet geactiveerd'}</dd>
                  </dl>
                  <div className="actions-row">
                    <button
                      className="secondary-button"
                      type="button"
                      disabled={resendingInvitation || Boolean((userDetail ?? editingUser).last_login_at) || (userDetail ?? editingUser).account_status !== 'active'}
                      onClick={() => void resendInvitation()}
                    >
                      <Mail size={16} /> {resendingInvitation ? 'Versturen...' : 'Uitnodiging opnieuw versturen'}
                    </button>
                  </div>
                  {invitationMessage ? <p className={invitationMessage.includes('mislukt') || invitationMessage.includes('al geactiveerd') ? 'form-error' : 'form-note'}>{invitationMessage}</p> : null}
                </div>
              ) : null}
              {modalMode === 'edit' && editingUser !== null && canManageUsers ? (
                <div className="form-grid__wide stacked-section">
                  <span className="field-label">MFA herstel</span>
                  <dl className="definition-grid">
                    <dt>Status</dt>
                    <dd>{(userDetail ?? editingUser).two_factor_enabled ? 'Ingeschakeld' : 'Uitgeschakeld'}</dd>
                  </dl>
                  <div className="actions-row">
                    <button className="secondary-button" type="button" disabled={resettingMfa || !(userDetail ?? editingUser).two_factor_enabled} onClick={() => void resetUserMfa()}>
                      <KeyRound size={16} /> {resettingMfa ? 'Resetten...' : 'MFA resetten'}
                    </button>
                  </div>
                </div>
              ) : null}
              {modalMode === 'edit' && editingUser !== null && canManageUsers ? (
                <div className="form-grid__wide stacked-section">
                  <span className="field-label">Loginbeveiliging</span>
                  <dl className="definition-grid">
                    <dt>Mislukte pogingen</dt>
                    <dd>{(userDetail ?? editingUser).failed_login_attempts ?? 0} / 5</dd>
                    <dt>Vergrendeld tot</dt>
                    <dd>{formatDateTime((userDetail ?? editingUser).login_locked_until)}</dd>
                  </dl>
                  <div className="actions-row">
                    <button
                      className="secondary-button"
                      type="button"
                      disabled={resettingLoginLock || (((userDetail ?? editingUser).failed_login_attempts ?? 0) === 0 && !(userDetail ?? editingUser).login_locked_until)}
                      onClick={() => void resetLoginLock()}
                    >
                      <KeyRound size={16} /> {resettingLoginLock ? 'Resetten...' : 'Loginlock resetten'}
                    </button>
                  </div>
                </div>
              ) : null}
              {error ? <p className="form-error form-grid__wide">{error}</p> : null}
              <div className="actions-row form-grid__wide">
                {modalMode === 'edit' && canManageUsers && canDeleteEditingUser ? (
                  <button className="danger-button" type="button" onClick={() => setDeletingUser(editingUser)}>
                    <Trash2 size={16} /> Verwijderen
                  </button>
                ) : null}
                <button className="secondary-button" type="button" onClick={() => setModalMode(null)}>Annuleren</button>
                {canManageUsers ? (
                  <button className="primary-button" type="submit" disabled={saving || roles.loading || teams.loading}>
                    {saving ? 'Opslaan...' : 'Opslaan'}
                  </button>
                ) : null}
              </div>
            </form>
          </section>
        </div>
      ) : null}

      {deletingUser !== null && canManageUsers ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal modal--narrow" role="dialog" aria-modal="true" aria-labelledby="delete-user-title">
            <header className="modal__header">
              <h2 id="delete-user-title">Gebruiker verwijderen</h2>
              <button className="icon-button" type="button" onClick={() => setDeletingUser(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <div className="confirm-dialog">
              <p>
                Weet je zeker dat je <strong>{deletingUser.name}</strong> wilt verwijderen?
              </p>
              <p className="muted-text">
                De gebruiker kan niet meer inloggen. Historische meldingen, rapportages en auditgegevens blijven behouden.
              </p>
              {error ? <p className="form-error">{error}</p> : null}
            </div>
            <div className="actions-row">
              <button className="secondary-button" type="button" onClick={() => setDeletingUser(null)} disabled={deleting}>
                Annuleren
              </button>
              <button className="danger-button" type="button" onClick={deleteUser} disabled={deleting}>
                {deleting ? 'Verwijderen...' : 'Ja, verwijderen'}
              </button>
            </div>
          </section>
        </div>
      ) : null}
    </div>
  );
}

function hasSystemAdministratorRole(user: User): boolean {
  return user.roles?.some((role) => role.name === 'system-administrator') ?? false;
}

interface UserOperationalDetailsProps {
  user: User | null;
  loading: boolean;
  error: string | null;
  assets: Asset[];
  assetsLoading: boolean;
  assetsError: string | null;
  certifications: Certification[];
  certificationsLoading: boolean;
  certificationsError: string | null;
  canManageAssets: boolean;
  canManageCertifications: boolean;
  canManageVacations: boolean;
  onChanged: () => Promise<void>;
}

function UserOperationalDetails({
  user,
  loading,
  error,
  assets,
  assetsLoading,
  assetsError,
  certifications: certificationOptions,
  certificationsLoading,
  certificationsError,
  canManageAssets,
  canManageCertifications,
  canManageVacations,
  onChanged,
}: UserOperationalDetailsProps) {
  const { api } = useAuth();
  const userCertifications = user?.certifications ?? [];
  const assetAssignments = user?.asset_assignments ?? [];
  const fcmTokens = user?.fcm_tokens ?? [];
  const assignedAssetIds = new Set(assetAssignments.map((assignment) => assignment.asset_id));
  const availableAssets = assets.filter((asset) => !assignedAssetIds.has(asset.id) && asset.status !== 'assigned' && asset.status !== 'retired');
  const userCertificationIds = new Set(userCertifications.map((certification) => certification.certification_id));
  const availableCertifications = certificationOptions.filter((certification) => !userCertificationIds.has(certification.id));
  const [assetId, setAssetId] = useState('');
  const [certificationId, setCertificationId] = useState('');
  const [issuedAt, setIssuedAt] = useState(todayInputValue());
  const [expiresAt, setExpiresAt] = useState('');
  const [certificateNumber, setCertificateNumber] = useState('');
  const [vacations, setVacations] = useState<UserVacation[]>([]);
  const [vacationsLoading, setVacationsLoading] = useState(false);
  const [vacationStartsAt, setVacationStartsAt] = useState(todayInputValue());
  const [vacationEndsAt, setVacationEndsAt] = useState(todayInputValue());
  const [vacationNote, setVacationNote] = useState('');
  const [linking, setLinking] = useState(false);
  const [linkError, setLinkError] = useState<string | null>(null);

  useEffect(() => {
    if (!canManageVacations || user === null) {
      setVacations([]);
      return;
    }

    let cancelled = false;
    setVacationsLoading(true);
    setLinkError(null);
    api.get<UserVacation[]>(`/users/${user.id}/vacations`)
      .then((response) => {
        if (!cancelled) {
          setVacations(response.data);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setLinkError(err instanceof ApiClientError ? err.message : 'Vakanties laden mislukt.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setVacationsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [api, canManageVacations, user]);

  async function assignAsset() {
    if (user === null || assetId === '') {
      return;
    }

    setLinking(true);
    setLinkError(null);
    try {
      await api.post(`/assets/${assetId}/assign`, { user_id: user.id });
      setAssetId('');
      await onChanged();
    } catch (err) {
      setLinkError(err instanceof ApiClientError ? err.message : 'Asset koppelen mislukt.');
    } finally {
      setLinking(false);
    }
  }

  async function assignCertification() {
    if (user === null || certificationId === '') {
      return;
    }

    setLinking(true);
    setLinkError(null);
    try {
      await api.post(`/users/${user.id}/certifications`, {
        certification_id: certificationId,
        issued_at: issuedAt,
        expires_at: expiresAt || null,
        certificate_number: certificateNumber || null,
        status: 'active',
      });
      setCertificationId('');
      setIssuedAt(todayInputValue());
      setExpiresAt('');
      setCertificateNumber('');
      await onChanged();
    } catch (err) {
      setLinkError(err instanceof ApiClientError ? err.message : 'Certificaat koppelen mislukt.');
    } finally {
      setLinking(false);
    }
  }

  async function createVacation() {
    if (user === null) {
      return;
    }

    setLinking(true);
    setLinkError(null);
    try {
      const response = await api.post<UserVacation>(`/users/${user.id}/vacations`, {
        starts_at: vacationStartsAt,
        ends_at: vacationEndsAt,
        note: vacationNote.trim() === '' ? null : vacationNote.trim(),
      });
      setVacations((current) => [...current, response.data].sort((a, b) => a.starts_at.localeCompare(b.starts_at)));
      setVacationStartsAt(todayInputValue());
      setVacationEndsAt(todayInputValue());
      setVacationNote('');
    } catch (err) {
      setLinkError(err instanceof ApiClientError ? err.message : 'Vakantie opslaan mislukt.');
    } finally {
      setLinking(false);
    }
  }

  async function cancelVacation(vacation: UserVacation) {
    setLinking(true);
    setLinkError(null);
    try {
      await api.delete(`/vacations/${vacation.id}`);
      setVacations((current) => current.filter((candidate) => candidate.id !== vacation.id));
    } catch (err) {
      setLinkError(err instanceof ApiClientError ? err.message : 'Vakantie intrekken mislukt.');
    } finally {
      setLinking(false);
    }
  }

  return (
    <div className="form-grid__wide stacked-section">
      {linkError ? <p className="form-error">{linkError}</p> : null}
      {canManageVacations ? (
        <div>
          <span className="field-label">Vakanties</span>
          <div className="inline-form inline-form--compact">
            <label>
              Begindatum
              <input type="date" value={vacationStartsAt} onChange={(event) => setVacationStartsAt(event.target.value)} disabled={user === null} />
            </label>
            <label>
              Einddatum
              <input type="date" value={vacationEndsAt} onChange={(event) => setVacationEndsAt(event.target.value)} disabled={user === null} />
            </label>
            <label>
              Notitie
              <input value={vacationNote} maxLength={1000} onChange={(event) => setVacationNote(event.target.value)} disabled={user === null} />
            </label>
            <button className="primary-button" type="button" disabled={linking || user === null || vacationStartsAt === '' || vacationEndsAt === ''} onClick={() => void createVacation()}>
              Toevoegen
            </button>
          </div>
          {vacationsLoading ? <p className="muted-text">Vakanties laden...</p> : null}
          {!vacationsLoading && vacations.length === 0 ? <p className="muted-text">Geen open vakanties geregistreerd.</p> : null}
          {vacations.length > 0 ? (
            <table className="data-table compact-table">
              <thead><tr><th>Begin</th><th>Eind</th><th>Status</th><th>Notitie</th><th>Actie</th></tr></thead>
              <tbody>
                {vacations.map((vacation) => (
                  <tr key={vacation.id}>
                    <td>{formatDate(vacation.starts_at)}</td>
                    <td>{formatDate(vacation.ends_at)}</td>
                    <td><StatusPill value={vacation.status} tone={vacation.status === 'active' ? 'warn' : 'neutral'} /></td>
                    <td>{vacation.note ?? '-'}</td>
                    <td>
                      {vacation.status === 'scheduled' || vacation.status === 'active' ? (
                        <button className="secondary-button" type="button" disabled={linking} onClick={() => void cancelVacation(vacation)}>Intrekken</button>
                      ) : '-'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : null}
        </div>
      ) : null}
      <div>
        <span className="field-label">Certificaten</span>
        {canManageCertifications ? <div className="inline-form inline-form--compact">
          <label>
            Certificaat
            <select value={certificationId} onChange={(event) => setCertificationId(event.target.value)} disabled={certificationsLoading || user === null}>
              <option value="">Selecteer certificaat</option>
              {availableCertifications.map((certification) => (
                <option key={certification.id} value={certification.id}>{certification.name}</option>
              ))}
            </select>
          </label>
          <label>
            Afgifte
            <input type="date" value={issuedAt} onChange={(event) => setIssuedAt(event.target.value)} />
          </label>
          <label>
            Verloopt
            <input type="date" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} />
          </label>
          <label>
            Nummer
            <input value={certificateNumber} onChange={(event) => setCertificateNumber(event.target.value)} />
          </label>
          <button className="primary-button" type="button" disabled={linking || certificationId === '' || user === null} onClick={() => void assignCertification()}>
            Koppelen
          </button>
        </div> : null}
        {certificationsError ? <p className="form-error">{certificationsError}</p> : null}
        {loading ? <p className="muted-text">Certificaten laden...</p> : null}
        {error ? <p className="form-error">{error}</p> : null}
        {!loading && userCertifications.length === 0 ? <p className="muted-text">Geen certificaten geregistreerd.</p> : null}
        {userCertifications.length > 0 ? (
          <table className="data-table compact-table">
            <thead><tr><th>Certificaat</th><th>Status</th><th>Nummer</th><th>Verloopt</th></tr></thead>
            <tbody>
              {userCertifications.map((certification) => (
                <tr key={certification.id}>
                  <td>{certification.certification?.name ?? certification.certification?.code ?? certification.certification_id}</td>
                  <td><StatusPill value={certification.status} tone={certification.status === 'active' ? 'good' : 'warn'} /></td>
                  <td>{certification.certificate_number ?? '-'}</td>
                  <td>{formatDate(certification.expires_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>

      <div>
        <span className="field-label">Assets</span>
        {canManageAssets ? <div className="inline-form inline-form--compact">
          <label>
            Asset
            <select value={assetId} onChange={(event) => setAssetId(event.target.value)} disabled={assetsLoading || user === null}>
              <option value="">Selecteer asset</option>
              {availableAssets.map((asset) => (
                <option key={asset.id} value={asset.id}>{assetDisplayLabel(asset)}</option>
              ))}
            </select>
          </label>
          <button className="primary-button" type="button" disabled={linking || assetId === '' || user === null} onClick={() => void assignAsset()}>
            Koppelen
          </button>
        </div> : null}
        {assetsError ? <p className="form-error">{assetsError}</p> : null}
        {loading ? <p className="muted-text">Assets laden...</p> : null}
        {!loading && assetAssignments.length === 0 ? <p className="muted-text">Geen actieve assets toegewezen.</p> : null}
        {assetAssignments.length > 0 ? (
          <table className="data-table compact-table">
            <thead><tr><th>Asset</th><th>Type</th><th>Status</th><th>Opties</th><th>Onderhoud</th><th>Toegewezen</th></tr></thead>
            <tbody>
              {assetAssignments.map((assignment) => {
                const asset = assignment.asset;
                const options = [
                  asset?.drone_type?.has_thermal ? 'Thermal' : null,
                  asset?.has_spotlight ? 'Lamp' : null,
                  asset?.has_speaker ? 'Speaker' : null,
                ].filter(Boolean).join(', ');

                return (
                  <tr key={assignment.id}>
                    <td>{asset ? assetDisplayLabel(asset) : '-'}</td>
                    <td>{asset?.drone_type ? droneTypeLabel(asset.drone_type) : asset?.type ?? '-'}</td>
                    <td>{asset ? <StatusPill value={asset.status} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' ? 'warn' : 'neutral'} /> : '-'}</td>
                    <td>{options || '-'}</td>
                    <td>{formatDate(asset?.maintenance_due_at)}</td>
                    <td>{formatDate(assignment.assigned_at)}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        ) : null}
      </div>

      <div>
        <span className="field-label">Gekoppelde toestellen</span>
        {loading ? <p className="muted-text">Toestellen laden...</p> : null}
        {!loading && fcmTokens.length === 0 ? <p className="muted-text">Geen toestellen gekoppeld.</p> : null}
        {fcmTokens.length > 0 ? (
          <table className="data-table compact-table">
            <thead><tr><th>Naam</th><th>Type</th><th>Toestel</th><th>App</th><th>Status</th><th>Laatst gezien</th></tr></thead>
            <tbody>
              {fcmTokens.map((token) => (
                <tr key={token.id}>
                  <td>{token.device_name ?? deviceLabel(token.device_manufacturer, token.device_model, token.device_id)}</td>
                  <td>{deviceTypeLabel(token.device_type)} / {token.client_type ?? 'operator'}</td>
                  <td>{deviceLabel(token.device_manufacturer, token.device_model, token.device_id)}{token.android_version ? ` - Android ${token.android_version}${token.sdk_version ? ` SDK ${token.sdk_version}` : ''}` : ''}</td>
                  <td>{token.app_version ?? '-'}</td>
                  <td><StatusPill value={token.is_online ? 'Online' : token.is_active ? 'Offline' : 'Uitgeschakeld'} tone={token.is_online ? 'good' : token.is_active ? 'neutral' : 'bad'} /></td>
                  <td>{formatDateTime(token.last_seen_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
    </div>
  );
}

function deviceLabel(manufacturer?: string | null, model?: string | null, fallback?: string | null): string {
  const label = [manufacturer, model].filter((value) => value !== undefined && value !== null && value !== '').join(' ');

  return label || fallback || '-';
}

function deviceTypeLabel(type?: string | null): string {
  if (type === 'tablet') {
    return 'Tablet';
  }

  if (type === 'phone') {
    return 'Telefoon';
  }

  return 'Onbekend';
}

function formatDate(value?: string | null): string {
  return formatDateOnly(value);
}

function todayInputValue(): string {
  return todayAmsterdamDateInputValue();
}
