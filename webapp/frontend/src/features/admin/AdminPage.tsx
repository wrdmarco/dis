import { Panel } from '../../components/Panel';
import { FirebaseSetupWizard } from '../../components/FirebaseSetupWizard';
import { ResourceState } from '../../components/ResourceState';
import { useApiResource } from '../../lib/useApiResource';
import type { FcmToken, ManualPushResult, Role, SystemSetting, Team, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { useEffect, useMemo, useState } from 'react';

interface MobileSettingsForm {
  tenantName: string;
  apiBaseUrl: string;
  firebaseApplicationId: string;
  firebaseApiKey: string;
  firebaseProjectId: string;
  firebaseMessagingSenderId: string;
  firebaseStorageBucket: string;
}

interface ManagedSettingsForm {
  mailMailer: string;
  mailHost: string;
  mailPort: string;
  mailEncryption: string;
  mailUsername: string;
  mailPassword: string;
  mailFromAddress: string;
  mailFromName: string;
  firebaseProjectId: string;
  pushLogRetentionDays: string;
  auditLogRetentionDays: string;
  locationRetentionDays: string;
  androidApplicationId: string;
}

interface ManualPushForm {
  title: string;
  body: string;
  teamIds: string[];
  roleIds: string[];
  userIds: string[];
}

const emptyPushForm: ManualPushForm = {
  title: '',
  body: '',
  teamIds: [],
  roleIds: [],
  userIds: [],
};

export function AdminPage() {
  const { api } = useAuth();
  const roles = useApiResource<Role[]>('/admin/roles');
  const teams = useApiResource<Team[]>('/admin/teams');
  const settings = useApiResource<SystemSetting[]>('/admin/settings');
  const users = useApiResource<User[]>('/users?per_page=200');
  const tokens = useApiResource<FcmToken[]>('/admin/push/tokens?per_page=100');
  const mobileSettings = useMemo(() => toMobileSettingsForm(settings.data ?? []), [settings.data]);
  const managedSettings = useMemo(() => toManagedSettingsForm(settings.data ?? []), [settings.data]);
  const [form, setForm] = useState<MobileSettingsForm>(mobileSettings);
  const [managedForm, setManagedForm] = useState<ManagedSettingsForm>(managedSettings);
  const [pushForm, setPushForm] = useState<ManualPushForm>(emptyPushForm);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [managedSaving, setManagedSaving] = useState(false);
  const [managedError, setManagedError] = useState<string | null>(null);
  const [pushSending, setPushSending] = useState(false);
  const [pushResult, setPushResult] = useState<string | null>(null);
  const [pushError, setPushError] = useState<string | null>(null);
  const [tokenActionId, setTokenActionId] = useState<string | null>(null);
  const [roleActionId, setRoleActionId] = useState<string | null>(null);

  useEffect(() => {
    setForm(mobileSettings);
  }, [mobileSettings]);

  useEffect(() => {
    setManagedForm((current) => ({ ...toManagedSettingsForm(settings.data ?? []), mailPassword: current.mailPassword }));
  }, [managedSettings, settings.data]);

  async function saveMobileSettings() {
    setSaving(true);
    setSaveError(null);
    try {
      await api.patch('/admin/settings', {
        settings: {
          'mobile.tenant_name': form.tenantName,
          'mobile.api_base_url': form.apiBaseUrl,
          'mobile.firebase_config': {
            application_id: form.firebaseApplicationId,
            api_key: form.firebaseApiKey,
            project_id: form.firebaseProjectId,
            messaging_sender_id: form.firebaseMessagingSenderId,
            storage_bucket: form.firebaseStorageBucket,
          },
        },
      });
      await settings.reload();
    } catch (error) {
      setSaveError(error instanceof Error ? error.message : 'Opslaan mislukt.');
    } finally {
      setSaving(false);
    }
  }

  async function sendManualPush() {
    setPushSending(true);
    setPushError(null);
    setPushResult(null);
    try {
      const response = await api.post<ManualPushResult>('/admin/push/manual', {
        title: pushForm.title,
        body: pushForm.body,
        team_ids: pushForm.teamIds,
        role_ids: pushForm.roleIds,
        user_ids: pushForm.userIds,
      });
      setPushResult(`${response.data.recipient_users} gebruikers, ${response.data.queued_tokens} push tokens in wachtrij.`);
      setPushForm(emptyPushForm);
    } catch (error) {
      setPushError(error instanceof Error ? error.message : 'Push melding versturen mislukt.');
    } finally {
      setPushSending(false);
    }
  }

  async function saveManagedSettings() {
    setManagedSaving(true);
    setManagedError(null);
    try {
      const payload: Record<string, unknown> = {
        'mail.mailer': managedForm.mailMailer,
        'mail.host': managedForm.mailHost,
        'mail.port': Number(managedForm.mailPort || 587),
        'mail.encryption': managedForm.mailEncryption,
        'mail.username': managedForm.mailUsername,
        'mail.from_address': managedForm.mailFromAddress,
        'mail.from_name': managedForm.mailFromName,
        'firebase.project_id': managedForm.firebaseProjectId,
        'retention.push_logs_days': Number(managedForm.pushLogRetentionDays || 90),
        'retention.audit_logs_days': Number(managedForm.auditLogRetentionDays || 3650),
        'retention.location_days': Number(managedForm.locationRetentionDays || 30),
        'updates.android.application_id': managedForm.androidApplicationId,
      };

      if (managedForm.mailPassword.trim() !== '') {
        payload['mail.password'] = managedForm.mailPassword;
      }

      await api.patch('/admin/settings', { settings: payload });
      setManagedForm((current) => ({ ...current, mailPassword: '' }));
      await settings.reload();
    } catch (error) {
      setManagedError(error instanceof Error ? error.message : 'Instellingen opslaan mislukt.');
    } finally {
      setManagedSaving(false);
    }
  }

  async function updateToken(token: FcmToken, action: 'activate' | 'revoke') {
    setTokenActionId(token.id);
    try {
      await api.post(`/admin/push/tokens/${token.id}/${action}`);
      await tokens.reload();
    } finally {
      setTokenActionId(null);
    }
  }

  async function toggleRoleMfa(role: Role) {
    setRoleActionId(role.id);
    try {
      await api.patch(`/admin/roles/${role.id}`, { requires_two_factor: !role.requires_two_factor });
      await roles.reload();
    } finally {
      setRoleActionId(null);
    }
  }

  return (
    <div className="page-stack">
      <div className="two-column">
        <Panel title="Rollen">
          <ResourceState loading={roles.loading} error={roles.error} empty={(roles.data?.length ?? 0) === 0}>
            <table className="data-table">
              <thead><tr><th>Naam</th><th>2FA</th><th>Permissies</th><th>Actie</th></tr></thead>
              <tbody>
                {roles.data?.map((role) => (
                  <tr key={role.id}>
                    <td>{role.display_name}</td>
                    <td>{role.requires_two_factor ? 'Verplicht' : 'Niet verplicht'}</td>
                    <td>{role.permissions?.length ?? 0}</td>
                    <td>
                      <button
                        className="secondary-button"
                        type="button"
                        disabled={roleActionId === role.id}
                        onClick={() => void toggleRoleMfa(role)}
                      >
                        {role.requires_two_factor ? 'MFA uit' : 'MFA aan'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ResourceState>
        </Panel>
        <Panel title="Teams">
          <ResourceState loading={teams.loading} error={teams.error} empty={(teams.data?.length ?? 0) === 0}>
            <table className="data-table">
              <thead><tr><th>Code</th><th>Naam</th><th>Type</th></tr></thead>
              <tbody>
                {teams.data?.map((team) => (
                  <tr key={team.id}>
                    <td>{team.code}</td>
                    <td>{team.name}</td>
                    <td>{team.type}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ResourceState>
        </Panel>
      </div>
      <Panel title="Firebase setup wizard">
        <FirebaseSetupWizard androidApplicationId={managedForm.androidApplicationId || 'nl.wrdmarco.dis'} />
      </Panel>
      <Panel title="Mobiele app tenantconfiguratie">
        <div className="form-grid">
          <label>
            Tenantnaam
            <input value={form.tenantName} onChange={(event) => setForm((current) => ({ ...current, tenantName: event.target.value }))} />
          </label>
          <label>
            Server URL
            <input value={form.apiBaseUrl} placeholder="http://dis.example.nl" onChange={(event) => setForm((current) => ({ ...current, apiBaseUrl: event.target.value }))} />
          </label>
          <label>
            Firebase application id
            <input value={form.firebaseApplicationId} onChange={(event) => setForm((current) => ({ ...current, firebaseApplicationId: event.target.value }))} />
          </label>
          <label>
            Firebase API key
            <input value={form.firebaseApiKey} onChange={(event) => setForm((current) => ({ ...current, firebaseApiKey: event.target.value }))} />
          </label>
          <label>
            Firebase project id
            <input value={form.firebaseProjectId} onChange={(event) => setForm((current) => ({ ...current, firebaseProjectId: event.target.value }))} />
          </label>
          <label>
            Firebase sender id
            <input value={form.firebaseMessagingSenderId} onChange={(event) => setForm((current) => ({ ...current, firebaseMessagingSenderId: event.target.value }))} />
          </label>
          <label>
            Firebase storage bucket
            <input value={form.firebaseStorageBucket} onChange={(event) => setForm((current) => ({ ...current, firebaseStorageBucket: event.target.value }))} />
          </label>
        </div>
        {saveError ? <p className="error-text">{saveError}</p> : null}
        <div className="actions-row">
          <button className="primary-button" type="button" onClick={saveMobileSettings} disabled={saving}>
            {saving ? 'Opslaan...' : 'Mobiele configuratie opslaan'}
          </button>
        </div>
      </Panel>
      <Panel title="Beheerbare systeeminstellingen">
        <div className="form-grid">
          <label>
            Mail driver
            <input value={managedForm.mailMailer} onChange={(event) => setManagedForm((current) => ({ ...current, mailMailer: event.target.value }))} />
          </label>
          <label>
            SMTP host
            <input value={managedForm.mailHost} onChange={(event) => setManagedForm((current) => ({ ...current, mailHost: event.target.value }))} />
          </label>
          <label>
            SMTP poort
            <input type="number" min="1" value={managedForm.mailPort} onChange={(event) => setManagedForm((current) => ({ ...current, mailPort: event.target.value }))} />
          </label>
          <label>
            SMTP encryptie
            <select value={managedForm.mailEncryption} onChange={(event) => setManagedForm((current) => ({ ...current, mailEncryption: event.target.value }))}>
              <option value="">Geen</option>
              <option value="tls">TLS</option>
              <option value="ssl">SSL</option>
            </select>
          </label>
          <label>
            SMTP gebruiker
            <input value={managedForm.mailUsername} onChange={(event) => setManagedForm((current) => ({ ...current, mailUsername: event.target.value }))} />
          </label>
          <label>
            SMTP wachtwoord
            <input type="password" value={managedForm.mailPassword} placeholder="Ongewijzigd laten" onChange={(event) => setManagedForm((current) => ({ ...current, mailPassword: event.target.value }))} />
          </label>
          <label>
            Afzender e-mail
            <input value={managedForm.mailFromAddress} onChange={(event) => setManagedForm((current) => ({ ...current, mailFromAddress: event.target.value }))} />
          </label>
          <label>
            Afzender naam
            <input value={managedForm.mailFromName} onChange={(event) => setManagedForm((current) => ({ ...current, mailFromName: event.target.value }))} />
          </label>
          <label>
            Firebase project id
            <input value={managedForm.firebaseProjectId} onChange={(event) => setManagedForm((current) => ({ ...current, firebaseProjectId: event.target.value }))} />
          </label>
          <label>
            Android application id
            <input value={managedForm.androidApplicationId} onChange={(event) => setManagedForm((current) => ({ ...current, androidApplicationId: event.target.value }))} />
          </label>
          <label>
            Push log retentie dagen
            <input type="number" min="1" value={managedForm.pushLogRetentionDays} onChange={(event) => setManagedForm((current) => ({ ...current, pushLogRetentionDays: event.target.value }))} />
          </label>
          <label>
            Audit log retentie dagen
            <input type="number" min="1" value={managedForm.auditLogRetentionDays} onChange={(event) => setManagedForm((current) => ({ ...current, auditLogRetentionDays: event.target.value }))} />
          </label>
          <label>
            Locatie retentie dagen
            <input type="number" min="1" value={managedForm.locationRetentionDays} onChange={(event) => setManagedForm((current) => ({ ...current, locationRetentionDays: event.target.value }))} />
          </label>
        </div>
        {managedError ? <p className="error-text">{managedError}</p> : null}
        <div className="actions-row">
          <button className="primary-button" type="button" onClick={saveManagedSettings} disabled={managedSaving}>
            {managedSaving ? 'Opslaan...' : 'Systeeminstellingen opslaan'}
          </button>
        </div>
      </Panel>
      <Panel title="Handmatige push melding">
        <div className="form-grid">
          <label>
            Titel
            <input value={pushForm.title} maxLength={120} onChange={(event) => setPushForm((current) => ({ ...current, title: event.target.value }))} />
          </label>
          <label>
            Gebruikers
            <select multiple value={pushForm.userIds} onChange={(event) => setPushForm((current) => ({ ...current, userIds: selectedValues(event.currentTarget) }))}>
              {users.data?.map((user) => (
                <option key={user.id} value={user.id}>{user.name} - {user.email}</option>
              ))}
            </select>
          </label>
          <label>
            Teams
            <select multiple value={pushForm.teamIds} onChange={(event) => setPushForm((current) => ({ ...current, teamIds: selectedValues(event.currentTarget) }))}>
              {teams.data?.map((team) => (
                <option key={team.id} value={team.id}>{team.code} - {team.name}</option>
              ))}
            </select>
          </label>
          <label>
            Rollen
            <select multiple value={pushForm.roleIds} onChange={(event) => setPushForm((current) => ({ ...current, roleIds: selectedValues(event.currentTarget) }))}>
              {roles.data?.map((role) => (
                <option key={role.id} value={role.id}>{role.display_name}</option>
              ))}
            </select>
          </label>
          <label className="form-grid__wide">
            Bericht
            <textarea value={pushForm.body} maxLength={1200} onChange={(event) => setPushForm((current) => ({ ...current, body: event.target.value }))} />
          </label>
        </div>
        {pushError ? <p className="error-text">{pushError}</p> : null}
        {pushResult ? <p className="success-text">{pushResult}</p> : null}
        <div className="actions-row">
          <button
            className="primary-button"
            type="button"
            onClick={sendManualPush}
            disabled={pushSending || !pushForm.title.trim() || !pushForm.body.trim() || recipientCount(pushForm) === 0}
          >
            {pushSending ? 'Versturen...' : 'Push melding versturen'}
          </button>
        </div>
      </Panel>
      <Panel title="Firebase tokens">
        <ResourceState loading={tokens.loading} error={tokens.error} empty={(tokens.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Gebruiker</th><th>Device</th><th>Platform</th><th>Token</th><th>Status</th><th>Laatst gezien</th><th>Actie</th></tr></thead>
            <tbody>
              {tokens.data?.map((token) => (
                <tr key={token.id}>
                  <td>{token.user?.name ?? '-'}</td>
                  <td>{token.device_id}</td>
                  <td>{token.platform} {token.app_version ?? ''}</td>
                  <td className="mono">{token.token_preview}</td>
                  <td>{token.is_active ? 'Actief' : 'Ingetrokken'}</td>
                  <td>{formatDate(token.last_seen_at)}</td>
                  <td>
                    <button
                      className="primary-button"
                      type="button"
                      disabled={tokenActionId === token.id}
                      onClick={() => void updateToken(token, token.is_active ? 'revoke' : 'activate')}
                    >
                      {token.is_active ? 'Intrekken' : 'Activeren'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
      <Panel title="Systeeminstellingen">
        <ResourceState loading={settings.loading} error={settings.error} empty={(settings.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Key</th><th>Waarde</th></tr></thead>
            <tbody>
              {settings.data?.map((setting) => (
                <tr key={setting.key}>
                  <td className="mono">{setting.key}</td>
                  <td className="mono">{JSON.stringify(setting.value)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}

function selectedValues(select: HTMLSelectElement): string[] {
  return Array.from(select.selectedOptions).map((option) => option.value);
}

function recipientCount(form: ManualPushForm): number {
  return form.teamIds.length + form.roleIds.length + form.userIds.length;
}

function formatDate(value?: string | null): string {
  return value ? new Date(value).toLocaleString('nl-NL') : '-';
}

function toMobileSettingsForm(settings: SystemSetting[]): MobileSettingsForm {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));
  const firebase = asRecord(byKey.get('mobile.firebase_config'));

  return {
    tenantName: asString(byKey.get('mobile.tenant_name')),
    apiBaseUrl: asString(byKey.get('mobile.api_base_url')),
    firebaseApplicationId: asString(firebase.application_id),
    firebaseApiKey: asString(firebase.api_key),
    firebaseProjectId: asString(firebase.project_id),
    firebaseMessagingSenderId: asString(firebase.messaging_sender_id),
    firebaseStorageBucket: asString(firebase.storage_bucket),
  };
}

function toManagedSettingsForm(settings: SystemSetting[]): ManagedSettingsForm {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));

  return {
    mailMailer: asString(byKey.get('mail.mailer')) || 'smtp',
    mailHost: asString(byKey.get('mail.host')),
    mailPort: asStringOrNumber(byKey.get('mail.port'), '587'),
    mailEncryption: asString(byKey.get('mail.encryption')),
    mailUsername: asString(byKey.get('mail.username')),
    mailPassword: '',
    mailFromAddress: asString(byKey.get('mail.from_address')),
    mailFromName: asString(byKey.get('mail.from_name')),
    firebaseProjectId: asString(byKey.get('firebase.project_id')),
    pushLogRetentionDays: asStringOrNumber(byKey.get('retention.push_logs_days'), '90'),
    auditLogRetentionDays: asStringOrNumber(byKey.get('retention.audit_logs_days'), '3650'),
    locationRetentionDays: asStringOrNumber(byKey.get('retention.location_days'), '30'),
    androidApplicationId: asString(byKey.get('updates.android.application_id')) || 'nl.nationaaldroneteam.dis',
  };
}

function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {};
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function asStringOrNumber(value: unknown, fallback: string): string {
  if (typeof value === 'number') {
    return String(value);
  }

  return typeof value === 'string' && value !== '' ? value : fallback;
}
