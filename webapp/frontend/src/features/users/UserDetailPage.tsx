'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft, KeyRound, LogOut, Mail, Pencil, Trash2, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { activeOperatorDeviceCount, onlineOperatorDeviceCount } from '../../lib/devicePresence';
import { locationLabel } from '../../lib/profileLocation';
import { useApiResource } from '../../lib/useApiResource';
import type { Asset, Certification, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { UserOperationalDetails } from './UserOperationalDetails';

interface RevokeSessionsResult {
  access_tokens_revoked: number;
  web_sessions_revoked: number;
  mobile_tokens_revoked: number;
}

export function UserDetailPage({ userId }: { userId: string }) {
  const router = useRouter();
  const { api, hasPermission, user: currentUser } = useAuth();
  const canManageUsers = hasPermission('users.manage');
  const canDeleteUsers = hasPermission('users.delete');
  const canResetMfa = hasPermission('users.mfa.reset');
  const canResetLoginLock = hasPermission('users.login-lock.reset');
  const canRevokeSessions = hasPermission('users.sessions.revoke');
  const canManageAssets = hasPermission('assets.manage');
  const canManageCertifications = hasPermission('certifications.manage');
  const targetUser = useApiResource<User>(`/users/${userId}`, Boolean(userId));
  const users = useApiResource<User[]>('/users', canManageUsers);
  const assets = useApiResource<Asset[]>('/assets', canManageAssets);
  const certifications = useApiResource<Certification[]>('/certifications', canManageCertifications);
  const [resettingMfa, setResettingMfa] = useState(false);
  const [resettingLoginLock, setResettingLoginLock] = useState(false);
  const [revokingSessions, setRevokingSessions] = useState(false);
  const [sessionMessage, setSessionMessage] = useState<string | null>(null);
  const [resendingInvitation, setResendingInvitation] = useState(false);
  const [invitationMessage, setInvitationMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const isSystemAdministrator = currentUser?.roles?.some((role) => role.name === 'system-administrator') ?? false;
  const activeSystemAdministratorCount = users.data?.filter(
    (candidate) => candidate.account_status === 'active' && hasSystemAdministratorRole(candidate),
  ).length ?? 0;
  const canDeleteTarget = targetUser.data !== null
    && canDeleteUsers
    && currentUser?.id !== targetUser.data.id
    && (
      !hasSystemAdministratorRole(targetUser.data)
      || (isSystemAdministrator && activeSystemAdministratorCount > 1)
    );

  async function reloadUser() {
    await Promise.all([targetUser.reload(), users.reload()]);
  }

  async function reloadOperationalDetails() {
    await Promise.all([targetUser.reload(), users.reload(), assets.reload()]);
  }

  async function resetUserMfa() {
    if (targetUser.data === null) {
      return;
    }

    setResettingMfa(true);
    setActionError(null);
    try {
      await api.post<User>(`/users/${targetUser.data.id}/2fa/reset`);
      await reloadUser();
    } catch (err) {
      setActionError(err instanceof ApiClientError ? err.message : 'MFA resetten mislukt.');
    } finally {
      setResettingMfa(false);
    }
  }

  async function resetLoginLock() {
    if (targetUser.data === null) {
      return;
    }

    setResettingLoginLock(true);
    setActionError(null);
    try {
      await api.post<User>(`/users/${targetUser.data.id}/login-lock/reset`);
      await reloadUser();
    } catch (err) {
      setActionError(err instanceof ApiClientError ? err.message : 'Loginvergrendeling resetten mislukt.');
    } finally {
      setResettingLoginLock(false);
    }
  }

  async function revokeUserSessions() {
    if (targetUser.data === null) {
      return;
    }

    const confirmed = window.confirm(`Alle sessies van ${targetUser.data.name} intrekken? De gebruiker wordt uitgelogd op web en mobiele apps en moet opnieuw inloggen.`);
    if (!confirmed) {
      return;
    }

    setRevokingSessions(true);
    setActionError(null);
    setSessionMessage(null);
    try {
      const response = await api.post<RevokeSessionsResult>(`/users/${targetUser.data.id}/sessions/revoke`);
      setSessionMessage([
        'Sessies ingetrokken.',
        `${response.data.access_tokens_revoked} toegangstoken(s)`,
        `${response.data.web_sessions_revoked} websessie(s)`,
        `${response.data.mobile_tokens_revoked} mobiele token(s)`,
      ].join(' '));
      await reloadUser();
    } catch (err) {
      setActionError(err instanceof ApiClientError ? err.message : 'Sessies intrekken mislukt.');
    } finally {
      setRevokingSessions(false);
    }
  }

  async function resendInvitation() {
    if (targetUser.data === null) {
      return;
    }

    setResendingInvitation(true);
    setActionError(null);
    setInvitationMessage(null);
    try {
      await api.post<User>(`/users/${targetUser.data.id}/invitation/resend`);
      setInvitationMessage('Uitnodiging is opnieuw verstuurd.');
      await reloadUser();
    } catch (err) {
      setInvitationMessage(err instanceof ApiClientError ? err.message : 'Uitnodiging opnieuw versturen mislukt.');
    } finally {
      setResendingInvitation(false);
    }
  }

  function openDeleteDialog() {
    setDeleteError(null);
    setDeleteDialogOpen(true);
  }

  async function deleteUser() {
    if (targetUser.data === null || !canDeleteTarget) {
      return;
    }

    setDeleting(true);
    setDeleteError(null);
    try {
      await api.delete(`/users/${targetUser.data.id}`);
      router.replace('/users');
    } catch (err) {
      setDeleteError(err instanceof ApiClientError ? err.message : 'Gebruiker kon niet worden verwijderd.');
      setDeleting(false);
    }
  }

  const user = targetUser.data;
  const onlineDevices = onlineOperatorDeviceCount(user?.fcm_tokens ?? []);
  const activeDevices = activeOperatorDeviceCount(user?.fcm_tokens ?? []);

  return (
    <div className="page-stack">
      <Panel
        title={user?.name ?? 'Gebruikersdetails'}
        action={(
          <div className="table-actions">
            <Link className="secondary-button" href="/users">
              <ArrowLeft size={16} /> Terug naar gebruikers
            </Link>
            {user !== null && canManageUsers ? (
              <Link className="secondary-button" href={`/users/${user.id}/edit`}>
                <Pencil size={16} /> Aanpassen
              </Link>
            ) : null}
            {canDeleteTarget ? (
              <button className="danger-button" type="button" onClick={openDeleteDialog}>
                <Trash2 size={16} /> Verwijderen
              </button>
            ) : null}
          </div>
        )}
      >
        <ResourceState loading={targetUser.loading} error={targetUser.error} empty={user === null}>
          {user !== null ? (
            <div className="panel-body">
              <dl className="definition-grid">
                <dt>Naam</dt>
                <dd>{user.name}</dd>
                <dt>E-mail</dt>
                <dd>{user.email}</dd>
                <dt>Telefoonnummer</dt>
                <dd>{user.phone_number ?? '-'}</dd>
                <dt>Locatie</dt>
                <dd>{locationLabel(user.home_city, user.home_region, user.home_country)}</dd>
                <dt>Accountstatus</dt>
                <dd><StatusPill value={user.account_status} tone={user.account_status === 'active' ? 'good' : 'bad'} /></dd>
                {canManageUsers ? (
                  <>
                    <dt>Laatste login</dt>
                    <dd>{formatDateTime(user.last_login_at)}</dd>
                    <dt>MFA</dt>
                    <dd>{user.two_factor_enabled ? 'Ingeschakeld' : 'Uitgeschakeld'}</dd>
                  </>
                ) : null}
                <dt>Online</dt>
                <dd>{onlineDevices > 0 ? `Online (${onlineDevices})` : 'Offline'}</dd>
                <dt>Push</dt>
                <dd>{user.push_enabled ? `Actief (${activeDevices}/${user.max_operator_devices ?? 1})` : 'Uit'}</dd>
                <dt>Max operator-devices</dt>
                <dd>{user.max_operator_devices ?? 1}</dd>
                <dt>Teams</dt>
                <dd>{user.teams?.map((team) => `${team.code} - ${team.name}`).join(', ') || '-'}</dd>
                <dt>Rollen</dt>
                <dd>{user.roles?.map((role) => role.display_name).join(', ') || '-'}</dd>
              </dl>
            </div>
          ) : null}
        </ResourceState>
      </Panel>

      {user !== null && canManageUsers ? (
        <Panel title="Accountbeveiliging">
          <div className="form-grid">
            {!user.last_login_at ? (
              <section className="form-grid__wide stacked-section">
                <span className="field-label">Uitnodiging</span>
                <dl className="definition-grid">
                  <dt>Activatie</dt>
                  <dd>Nog niet geactiveerd</dd>
                </dl>
                <div className="actions-row">
                  <button
                    className="secondary-button"
                    type="button"
                    disabled={resendingInvitation || user.account_status !== 'active'}
                    onClick={() => void resendInvitation()}
                  >
                    <Mail size={16} /> {resendingInvitation ? 'Versturen...' : 'Uitnodiging opnieuw versturen'}
                  </button>
                </div>
                {invitationMessage ? <p className={invitationMessage.includes('mislukt') || invitationMessage.includes('al geactiveerd') ? 'form-error' : 'form-note'}>{invitationMessage}</p> : null}
              </section>
            ) : null}

            {canResetMfa ? <section className="form-grid__wide stacked-section">
              <span className="field-label">MFA herstel</span>
              <dl className="definition-grid">
                <dt>Status</dt>
                <dd>{user.two_factor_enabled ? 'Ingeschakeld' : 'Uitgeschakeld'}</dd>
              </dl>
              <div className="actions-row">
                <button className="secondary-button" type="button" disabled={resettingMfa || !user.two_factor_enabled} onClick={() => void resetUserMfa()}>
                  <KeyRound size={16} /> {resettingMfa ? 'Resetten...' : 'MFA resetten'}
                </button>
              </div>
            </section> : null}

            {canResetLoginLock ? <section className="form-grid__wide stacked-section">
              <span className="field-label">Loginbeveiliging</span>
              <dl className="definition-grid">
                <dt>Mislukte pogingen</dt>
                <dd>{user.failed_login_attempts ?? 0} / 5</dd>
                <dt>Vergrendeld tot</dt>
                <dd>{formatDateTime(user.login_locked_until)}</dd>
              </dl>
              <div className="actions-row">
                <button
                  className="secondary-button"
                  type="button"
                  disabled={resettingLoginLock || ((user.failed_login_attempts ?? 0) === 0 && !user.login_locked_until)}
                  onClick={() => void resetLoginLock()}
                >
                  <KeyRound size={16} /> {resettingLoginLock ? 'Resetten...' : 'Loginlock resetten'}
                </button>
              </div>
            </section> : null}

            {canRevokeSessions ? <section className="form-grid__wide stacked-section">
              <span className="field-label">Sessies</span>
              <p className="muted-text">
                Trek alle web- en app-sessies van deze gebruiker in. Actieve mobiele tokens worden gedeactiveerd en de gebruiker moet opnieuw inloggen.
              </p>
              <div className="actions-row">
                <button
                  className="danger-button"
                  type="button"
                  disabled={revokingSessions || currentUser?.id === user.id}
                  onClick={() => void revokeUserSessions()}
                >
                  <LogOut size={16} /> {revokingSessions ? 'Intrekken...' : 'Sessies intrekken'}
                </button>
              </div>
              {currentUser?.id === user.id ? <p className="form-note">Je eigen sessies intrekken kan niet via gebruikersbeheer.</p> : null}
              {sessionMessage ? <p className="form-note">{sessionMessage}</p> : null}
            </section> : null}

            {actionError ? <p className="form-error form-grid__wide" role="alert">{actionError}</p> : null}
          </div>
        </Panel>
      ) : null}

      {user !== null ? (
        <UserOperationalDetails
          user={user}
          loading={targetUser.loading}
          error={targetUser.error}
          assets={canManageAssets ? assets.data ?? [] : []}
          assetsLoading={canManageAssets && assets.loading}
          assetsError={canManageAssets ? assets.error : null}
          certifications={canManageCertifications ? certifications.data ?? [] : []}
          certificationsLoading={canManageCertifications && certifications.loading}
          certificationsError={canManageCertifications ? certifications.error : null}
          canManageAssets={canManageAssets}
          canManageCertifications={canManageCertifications}
          canManageVacations={canManageUsers}
          onChanged={reloadOperationalDetails}
        />
      ) : null}

      {deleteDialogOpen && user !== null && canDeleteTarget ? (
        <DeleteUserDialog
          user={user}
          deleting={deleting}
          error={deleteError}
          onCancel={() => setDeleteDialogOpen(false)}
          onConfirm={() => void deleteUser()}
        />
      ) : null}
    </div>
  );
}

function DeleteUserDialog({
  user,
  deleting,
  error,
  onCancel,
  onConfirm,
}: {
  user: User;
  deleting: boolean;
  error: string | null;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <div className="modal-backdrop" role="presentation">
      <section className="modal modal--narrow" role="dialog" aria-modal="true" aria-labelledby="delete-user-title">
        <header className="modal__header">
          <h2 id="delete-user-title">Gebruiker verwijderen</h2>
          <button className="icon-button" type="button" onClick={onCancel} aria-label="Sluiten" disabled={deleting}>
            <X size={18} />
          </button>
        </header>
        <div className="confirm-dialog">
          <p>
            Weet je zeker dat je <strong>{user.name}</strong> wilt verwijderen?
          </p>
          <p className="muted-text">
            De gebruiker kan niet meer inloggen. Historische meldingen, rapportages en auditgegevens blijven behouden.
          </p>
          {error ? <p className="form-error" role="alert">{error}</p> : null}
        </div>
        <div className="actions-row">
          <button className="secondary-button" type="button" onClick={onCancel} disabled={deleting}>
            Annuleren
          </button>
          <button className="danger-button" type="button" onClick={onConfirm} disabled={deleting}>
            {deleting ? 'Verwijderen...' : 'Ja, verwijderen'}
          </button>
        </div>
      </section>
    </div>
  );
}

function hasSystemAdministratorRole(user: User): boolean {
  return user.roles?.some((role) => role.name === 'system-administrator') ?? false;
}
