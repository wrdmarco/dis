import { Panel } from '../../components/Panel';
import { FirebaseSetupWizard } from '../../components/FirebaseSetupWizard';
import { ResourceState } from '../../components/ResourceState';
import { TotpQrCode } from '../../components/TotpQrCode';
import { parseFirebaseJson } from '../../lib/firebaseConfigImport';
import { useApiResource } from '../../lib/useApiResource';
import type { FcmToken, Role, SystemSetting, Team } from '../../types/api';
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
  firebaseServiceClientEmail: string;
  firebaseServicePrivateKey: string;
  firebaseServicePrivateKeyId: string;
  firebaseServiceClientId: string;
  firebaseServiceClientX509CertUrl: string;
  pushLogRetentionDays: string;
  auditLogRetentionDays: string;
  locationRetentionDays: string;
  androidApplicationId: string;
}

type AdminTab = 'access' | 'firebase' | 'system' | 'tokens' | 'settings';

const adminTabs: Array<{ id: AdminTab; label: string }> = [
  { id: 'access', label: 'Toegang' },
  { id: 'firebase', label: 'Firebase' },
  { id: 'system', label: 'Systeem' },
  { id: 'tokens', label: 'Tokens' },
  { id: 'settings', label: 'Instellingen' },
];

export function AdminPage() {
  const { api } = useAuth();
  const roles = useApiResource<Role[]>('/admin/roles');
  const teams = useApiResource<Team[]>('/admin/teams');
  const settings = useApiResource<SystemSetting[]>('/admin/settings');
  const tokens = useApiResource<FcmToken[]>('/admin/push/tokens?per_page=100');
  const mobileSettings = useMemo(() => toMobileSettingsForm(settings.data ?? []), [settings.data]);
  const managedSettings = useMemo(() => toManagedSettingsForm(settings.data ?? []), [settings.data]);
  const [form, setForm] = useState<MobileSettingsForm>(mobileSettings);
  const [managedForm, setManagedForm] = useState<ManagedSettingsForm>(managedSettings);
  const [activeTab, setActiveTab] = useState<AdminTab>('access');
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [managedSaving, setManagedSaving] = useState(false);
  const [managedError, setManagedError] = useState<string | null>(null);
  const [tokenActionId, setTokenActionId] = useState<string | null>(null);
  const [roleActionId, setRoleActionId] = useState<string | null>(null);

  useEffect(() => {
    setForm(mobileSettings);
  }, [mobileSettings]);

  useEffect(() => {
    setManagedForm((current) => ({
      ...toManagedSettingsForm(settings.data ?? []),
      mailPassword: current.mailPassword,
      firebaseServicePrivateKey: current.firebaseServicePrivateKey,
    }));
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

  async function saveFirebaseSettings() {
    setSaving(true);
    setManagedSaving(true);
    setSaveError(null);
    setManagedError(null);

    try {
      const payload: Record<string, unknown> = {
        'firebase.project_id': managedForm.firebaseProjectId || form.firebaseProjectId,
        'mobile.firebase_config': {
          application_id: form.firebaseApplicationId,
          api_key: form.firebaseApiKey,
          project_id: form.firebaseProjectId || managedForm.firebaseProjectId,
          messaging_sender_id: form.firebaseMessagingSenderId,
          storage_bucket: form.firebaseStorageBucket,
        },
      };

      if ([
        managedForm.firebaseServiceClientEmail,
        managedForm.firebaseServicePrivateKey,
        managedForm.firebaseServicePrivateKeyId,
        managedForm.firebaseServiceClientId,
        managedForm.firebaseServiceClientX509CertUrl,
      ].some((value) => value.trim() !== '')) {
        payload['firebase.service_account'] = {
          client_email: managedForm.firebaseServiceClientEmail,
          private_key: managedForm.firebaseServicePrivateKey,
          private_key_id: managedForm.firebaseServicePrivateKeyId,
          client_id: managedForm.firebaseServiceClientId,
          client_x509_cert_url: managedForm.firebaseServiceClientX509CertUrl,
        };
      }

      await api.patch('/admin/settings', { settings: payload });
      setManagedForm((current) => ({ ...current, firebaseServicePrivateKey: '' }));
      await settings.reload();
    } catch (error) {
      setManagedError(error instanceof Error ? error.message : 'Firebase configuratie opslaan mislukt.');
    } finally {
      setSaving(false);
      setManagedSaving(false);
    }
  }

  async function importFirebaseJson(file: File | null) {
    if (file === null) {
      return;
    }

    setSaveError(null);
    setManagedError(null);

    try {
      const imported = parseFirebaseJson(await file.text());
      setForm((current) => ({
        ...current,
        firebaseProjectId: imported.projectId ?? current.firebaseProjectId,
        firebaseApplicationId: imported.applicationId ?? current.firebaseApplicationId,
        firebaseApiKey: imported.apiKey ?? current.firebaseApiKey,
        firebaseMessagingSenderId: imported.messagingSenderId ?? current.firebaseMessagingSenderId,
        firebaseStorageBucket: imported.storageBucket ?? current.firebaseStorageBucket,
      }));
      setManagedForm((current) => ({
        ...current,
        firebaseProjectId: imported.projectId ?? current.firebaseProjectId,
        firebaseServiceClientEmail: imported.serviceAccount?.clientEmail ?? current.firebaseServiceClientEmail,
        firebaseServicePrivateKey: imported.serviceAccount?.privateKey ?? current.firebaseServicePrivateKey,
        firebaseServicePrivateKeyId: imported.serviceAccount?.privateKeyId ?? current.firebaseServicePrivateKeyId,
        firebaseServiceClientId: imported.serviceAccount?.clientId ?? current.firebaseServiceClientId,
        firebaseServiceClientX509CertUrl: imported.serviceAccount?.clientX509CertUrl ?? current.firebaseServiceClientX509CertUrl,
      }));
    } catch (error) {
      setManagedError(error instanceof Error ? error.message : 'Firebase JSON importeren mislukt.');
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

      if ([
        managedForm.firebaseServiceClientEmail,
        managedForm.firebaseServicePrivateKey,
        managedForm.firebaseServicePrivateKeyId,
        managedForm.firebaseServiceClientId,
        managedForm.firebaseServiceClientX509CertUrl,
      ].some((value) => value.trim() !== '')) {
        payload['firebase.service_account'] = {
          client_email: managedForm.firebaseServiceClientEmail,
          private_key: managedForm.firebaseServicePrivateKey,
          private_key_id: managedForm.firebaseServicePrivateKeyId,
          client_id: managedForm.firebaseServiceClientId,
          client_x509_cert_url: managedForm.firebaseServiceClientX509CertUrl,
        };
      }

      await api.patch('/admin/settings', { settings: payload });
      setManagedForm((current) => ({ ...current, mailPassword: '', firebaseServicePrivateKey: '' }));
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
      <div className="admin-tabs" role="tablist" aria-label="Admin onderdelen">
        {adminTabs.map((tab) => (
          <button
            className={activeTab === tab.id ? 'admin-tab admin-tab--active' : 'admin-tab'}
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={activeTab === tab.id}
            onClick={() => setActiveTab(tab.id)}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {activeTab === 'access' ? (
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
      ) : null}

      {activeTab === 'firebase' ? (
        <>
          <Panel title="Firebase setup wizard">
            <FirebaseSetupWizard androidApplicationId={managedForm.androidApplicationId || 'nl.wrdmarco.dis'} />
          </Panel>
          <Panel title="Firebase JSON importeren">
            <div className="setup-copy">
              <strong>Upload het Firebase JSON-bestand hier.</strong>
              <p>DIS leest het bestand in je browser en vult de velden automatisch. Het JSON-bestand zelf wordt niet op de server geplaatst.</p>
            </div>
            <div className="form-grid">
              <label className="form-grid__wide">
                Firebase JSON
                <input
                  accept="application/json,.json"
                  type="file"
                  onChange={(event) => {
                    void importFirebaseJson(event.currentTarget.files?.[0] ?? null);
                    event.currentTarget.value = '';
                  }}
                />
              </label>
            </div>
            <div className="actions-row">
              <button className="primary-button" type="button" onClick={saveFirebaseSettings} disabled={saving || managedSaving}>
                {saving || managedSaving ? 'Opslaan...' : 'Firebase configuratie opslaan'}
              </button>
            </div>
          </Panel>
          <Panel title="Mobiele app tenantconfiguratie">
            {form.apiBaseUrl.trim() !== '' ? (
              <div className="tenant-qr">
                <TotpQrCode value={normalizePublicUrl(form.apiBaseUrl)} alt="QR-code met DIS server URL" helpText="Scan deze QR-code in de Android app om de server URL automatisch in te vullen." />
                <code>{normalizePublicUrl(form.apiBaseUrl)}</code>
              </div>
            ) : null}
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
        </>
      ) : null}

      {activeTab === 'system' ? (
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
              Firebase service account client_email
              <input value={managedForm.firebaseServiceClientEmail} onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServiceClientEmail: event.target.value }))} />
            </label>
            <label>
              Firebase service account private_key_id
              <input value={managedForm.firebaseServicePrivateKeyId} onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServicePrivateKeyId: event.target.value }))} />
            </label>
            <label>
              Firebase service account client_id
              <input value={managedForm.firebaseServiceClientId} onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServiceClientId: event.target.value }))} />
            </label>
            <label>
              Firebase service account cert URL
              <input value={managedForm.firebaseServiceClientX509CertUrl} onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServiceClientX509CertUrl: event.target.value }))} />
            </label>
            <label className="form-grid__wide">
              Firebase service account private_key
              <textarea className="mono" value={managedForm.firebaseServicePrivateKey} placeholder="Ongewijzigd laten" onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServicePrivateKey: event.target.value }))} />
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
      ) : null}

      {activeTab === 'tokens' ? (
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
      ) : null}

      {activeTab === 'settings' ? (
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
      ) : null}
    </div>
  );
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
  const serviceAccount = asRecord(byKey.get('firebase.service_account'));

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
    firebaseServiceClientEmail: asString(serviceAccount.client_email),
    firebaseServicePrivateKey: '',
    firebaseServicePrivateKeyId: asString(serviceAccount.private_key_id),
    firebaseServiceClientId: asString(serviceAccount.client_id),
    firebaseServiceClientX509CertUrl: asString(serviceAccount.client_x509_cert_url),
    pushLogRetentionDays: asStringOrNumber(byKey.get('retention.push_logs_days'), '90'),
    auditLogRetentionDays: asStringOrNumber(byKey.get('retention.audit_logs_days'), '3650'),
    locationRetentionDays: asStringOrNumber(byKey.get('retention.location_days'), '30'),
    androidApplicationId: asString(byKey.get('updates.android.application_id')) || 'nl.wrdmarco.dis',
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

function normalizePublicUrl(value: string): string {
  const trimmed = value.trim().replace(/\/+$/, '');

  if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
    return trimmed;
  }

  return `http://${trimmed}`;
}
