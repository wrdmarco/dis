import { Panel } from '../../components/Panel';
import { FirebaseSetupWizard } from '../../components/FirebaseSetupWizard';
import { ResourceState } from '../../components/ResourceState';
import { TotpQrCode } from '../../components/TotpQrCode';
import { parseFirebaseJson } from '../../lib/firebaseConfigImport';
import { formatDateTime } from '../../lib/dateTime';
import { createRealtime } from '../../lib/realtime';
import { useApiResource } from '../../lib/useApiResource';
import type { DeveloperAccessState, FcmToken, Role, SystemSetting, SystemUpdateStatus, SystemVersionState } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { useEffect, useMemo, useState } from 'react';
import { ApiClientError } from '../../lib/apiClient';

interface MobileSettingsForm {
  tenantName: string;
  publicUrl: string;
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
  mailMicrosoft365TenantId: string;
  mailMicrosoft365ClientId: string;
  mailMicrosoft365ClientSecret: string;
  mailMicrosoft365Sender: string;
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

interface PasswordPolicySettingsForm {
  minimumLength: string;
  requiresMixedCase: boolean;
  requiresNumbers: boolean;
  requiresSymbols: boolean;
  uncompromised: boolean;
}

type AdminTab = 'access' | 'firebase' | 'mail' | 'system' | 'passwords' | 'developer' | 'version' | 'tokens' | 'settings';

const adminTabs: Array<{ id: AdminTab; label: string }> = [
  { id: 'access', label: 'Toegang' },
  { id: 'firebase', label: 'Firebase' },
  { id: 'mail', label: 'Mail' },
  { id: 'system', label: 'Systeem' },
  { id: 'passwords', label: 'Wachtwoorden' },
  { id: 'developer', label: 'Ontwikkel' },
  { id: 'version', label: 'Versie' },
  { id: 'tokens', label: 'Tokens' },
  { id: 'settings', label: 'Instellingen' },
];

export function AdminPage() {
  const { api, token } = useAuth();
  const roles = useApiResource<Role[]>('/admin/roles');
  const settings = useApiResource<SystemSetting[]>('/admin/settings');
  const tokens = useApiResource<FcmToken[]>('/admin/push/tokens?per_page=100');
  const developerAccess = useApiResource<DeveloperAccessState>('/admin/developer-access');
  const systemVersion = useApiResource<SystemVersionState>('/admin/system/version');
  const mobileSettings = useMemo(() => toMobileSettingsForm(settings.data ?? []), [settings.data]);
  const managedSettings = useMemo(() => toManagedSettingsForm(settings.data ?? []), [settings.data]);
  const passwordPolicySettings = useMemo(() => toPasswordPolicySettingsForm(settings.data ?? []), [settings.data]);
  const [form, setForm] = useState<MobileSettingsForm>(mobileSettings);
  const [managedForm, setManagedForm] = useState<ManagedSettingsForm>(managedSettings);
  const [passwordPolicyForm, setPasswordPolicyForm] = useState<PasswordPolicySettingsForm>(passwordPolicySettings);
  const [activeTab, setActiveTab] = useState<AdminTab>('access');
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [managedSaving, setManagedSaving] = useState(false);
  const [managedError, setManagedError] = useState<string | null>(null);
  const [managedMessage, setManagedMessage] = useState<string | null>(null);
  const [tokenActionId, setTokenActionId] = useState<string | null>(null);
  const [tokenActionError, setTokenActionError] = useState<string | null>(null);
  const [roleActionId, setRoleActionId] = useState<string | null>(null);
  const [developerActionError, setDeveloperActionError] = useState<string | null>(null);
  const [generatedDeveloperKey, setGeneratedDeveloperKey] = useState<string | null>(null);
  const [developerSaving, setDeveloperSaving] = useState(false);
  const [updaterStatus, setUpdaterStatus] = useState<SystemUpdateStatus | null>(null);
  const [updateActionError, setUpdateActionError] = useState<string | null>(null);
  const [updateStarting, setUpdateStarting] = useState(false);

  useEffect(() => {
    setForm(mobileSettings);
  }, [mobileSettings]);

  useEffect(() => {
    setManagedForm((current) => ({
      ...toManagedSettingsForm(settings.data ?? []),
      mailPassword: current.mailPassword,
      mailMicrosoft365ClientSecret: current.mailMicrosoft365ClientSecret,
      firebaseServicePrivateKey: current.firebaseServicePrivateKey,
    }));
  }, [managedSettings, settings.data]);

  useEffect(() => {
    setPasswordPolicyForm(passwordPolicySettings);
  }, [passwordPolicySettings]);

  useEffect(() => {
    setUpdaterStatus(systemVersion.data?.updater ?? null);
  }, [systemVersion.data?.updater]);

  const reloadSystemVersionSilently = systemVersion.silentReload;

  useEffect(() => {
    if (token === null) {
      return;
    }

    const echo = createRealtime({
      token,
      onSystemUpdateStatus: (payload) => {
        const status = payload as SystemUpdateStatus;
        setUpdaterStatus(status);
        if (status.state === 'succeeded' || status.state === 'failed') {
          void reloadSystemVersionSilently();
        }
      },
    });

    return () => {
      echo.leave('private-admin.system');
    };
  }, [reloadSystemVersionSilently, token]);

  async function saveMobileSettings() {
    setSaving(true);
    setSaveError(null);
    try {
      const sourceUrl = form.publicUrl || form.apiBaseUrl;

      if (sourceUrl.trim() === '') {
        setSaveError('Publieke web URL is verplicht.');
        return;
      }

      const publicUrl = normalizeWebPublicUrl(sourceUrl);

      await api.patch('/admin/settings', {
        settings: {
          'mobile.tenant_name': form.tenantName,
          'app.public_url': publicUrl,
          'mobile.api_base_url': normalizeApiBaseUrl(form.apiBaseUrl || publicUrl),
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

      if (managedForm.firebaseServicePrivateKey.trim() !== '') {
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
    setManagedMessage(null);
    try {
      const payload: Record<string, unknown> = {
        'retention.push_logs_days': Number(managedForm.pushLogRetentionDays || 90),
        'retention.audit_logs_days': Number(managedForm.auditLogRetentionDays || 3650),
        'retention.location_days': Number(managedForm.locationRetentionDays || 30),
        'updates.android.application_id': managedForm.androidApplicationId,
      };

      await api.patch('/admin/settings', { settings: payload });
      await settings.reload();
    } catch (error) {
      setManagedError(error instanceof Error ? error.message : 'Instellingen opslaan mislukt.');
    } finally {
      setManagedSaving(false);
    }
  }

  async function saveMailSettings() {
    setManagedSaving(true);
    setManagedError(null);
    try {
      const payload: Record<string, unknown> = {
        'mail.mailer': managedForm.mailMailer,
        'mail.host': managedForm.mailHost,
        'mail.port': Number(managedForm.mailPort || 587),
        'mail.encryption': managedForm.mailEncryption,
        'mail.username': managedForm.mailUsername,
        'mail.microsoft365_tenant_id': managedForm.mailMicrosoft365TenantId,
        'mail.microsoft365_client_id': managedForm.mailMicrosoft365ClientId,
        'mail.microsoft365_sender': managedForm.mailMicrosoft365Sender,
        'mail.from_address': managedForm.mailFromAddress,
        'mail.from_name': managedForm.mailFromName,
      };

      if (managedForm.mailPassword.trim() !== '') {
        payload['mail.password'] = managedForm.mailPassword;
      }
      if (managedForm.mailMicrosoft365ClientSecret.trim() !== '') {
        payload['mail.microsoft365_client_secret'] = managedForm.mailMicrosoft365ClientSecret;
      }

      await api.patch('/admin/settings', { settings: payload });
      setManagedForm((current) => ({ ...current, mailPassword: '', mailMicrosoft365ClientSecret: '' }));
      await settings.reload();
    } catch (error) {
      setManagedError(error instanceof Error ? error.message : 'Mailinstellingen opslaan mislukt.');
    } finally {
      setManagedSaving(false);
    }
  }

  async function sendTestMail() {
    setManagedSaving(true);
    setManagedError(null);
    setManagedMessage(null);
    try {
      await api.post('/admin/settings/mail/test');
      setManagedMessage('Testmail is verzonden naar je eigen e-mailadres.');
    } catch (error) {
      setManagedError(error instanceof Error ? error.message : 'Testmail verzenden mislukt.');
    } finally {
      setManagedSaving(false);
    }
  }

  async function savePasswordPolicySettings() {
    setManagedSaving(true);
    setManagedError(null);
    try {
      const minimumLength = Number(passwordPolicyForm.minimumLength || 14);

      await api.patch('/admin/settings', {
        settings: {
          'security.password_min_length': minimumLength,
          'security.password_requires_mixed_case': passwordPolicyForm.requiresMixedCase,
          'security.password_requires_numbers': passwordPolicyForm.requiresNumbers,
          'security.password_requires_symbols': passwordPolicyForm.requiresSymbols,
          'security.password_uncompromised': passwordPolicyForm.uncompromised,
        },
      });
      await settings.reload();
    } catch (error) {
      setManagedError(error instanceof Error ? error.message : 'Wachtwoordeisen opslaan mislukt.');
    } finally {
      setManagedSaving(false);
    }
  }

  async function updateToken(token: FcmToken, action: 'activate' | 'revoke') {
    setTokenActionId(token.id);
    setTokenActionError(null);
    try {
      await api.post<null>(`/admin/push/tokens/${token.id}/${action}`);
      await tokens.reload();
    } catch (error) {
      setTokenActionError(error instanceof ApiClientError ? error.message : 'Tokenactie mislukt.');
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

  async function generateDeveloperKey() {
    setDeveloperSaving(true);
    setDeveloperActionError(null);
    setGeneratedDeveloperKey(null);
    try {
      const response = await api.post<DeveloperAccessState>('/admin/developer-access/key');
      setGeneratedDeveloperKey(response.data.api_key ?? null);
      await developerAccess.reload();
    } catch (error) {
      setDeveloperActionError(error instanceof ApiClientError ? error.message : 'Developer sleutel genereren mislukt.');
    } finally {
      setDeveloperSaving(false);
    }
  }

  async function disableDeveloperKey() {
    setDeveloperSaving(true);
    setDeveloperActionError(null);
    setGeneratedDeveloperKey(null);
    try {
      await api.delete<DeveloperAccessState>('/admin/developer-access/key');
      await developerAccess.reload();
    } catch (error) {
      setDeveloperActionError(error instanceof ApiClientError ? error.message : 'Developer toegang uitschakelen mislukt.');
    } finally {
      setDeveloperSaving(false);
    }
  }

  async function startServerUpdate() {
    setUpdateStarting(true);
    setUpdateActionError(null);
    try {
      const response = await api.post<SystemUpdateStatus>('/admin/system/update');
      setUpdaterStatus(response.data);
      await systemVersion.silentReload();
    } catch (error) {
      setUpdateActionError(error instanceof ApiClientError ? error.message : 'Update starten mislukt.');
    } finally {
      setUpdateStarting(false);
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
      ) : null}

      {activeTab === 'firebase' ? (
        <>
          <Panel title="Firebase setup wizard">
            <FirebaseSetupWizard androidApplicationId={managedForm.androidApplicationId || 'nl.wrdmarco.dis'} />
          </Panel>
          <Panel title="Firebase JSON importeren">
            <div className="setup-copy">
              <strong>Upload hier je Firebase JSON-bestanden.</strong>
              <p>Voor de Android app is de Firebase Android config JSON nodig. Voor pushberichten vanuit de backend is een Firebase service-account JSON nodig. DIS leest het bestand in je browser en slaat alleen de benodigde waarden op.</p>
              <p>Service account: {isFirebaseServiceAccountConfigured(settings.data ?? [], managedForm) ? 'ingesteld' : 'niet ingesteld'}</p>
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
            <div className="form-grid">
              <label>
                Service account client email
                <input value={managedForm.firebaseServiceClientEmail} onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServiceClientEmail: event.target.value }))} />
              </label>
              <label>
                Service account private key id
                <input value={managedForm.firebaseServicePrivateKeyId} onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServicePrivateKeyId: event.target.value }))} />
              </label>
              <label>
                Service account client id
                <input value={managedForm.firebaseServiceClientId} onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServiceClientId: event.target.value }))} />
              </label>
              <label>
                Service account certificaat URL
                <input value={managedForm.firebaseServiceClientX509CertUrl} onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServiceClientX509CertUrl: event.target.value }))} />
              </label>
              <label className="form-grid__wide">
                Service account private key
                <textarea
                  className="mono"
                  rows={8}
                  value={managedForm.firebaseServicePrivateKey}
                  placeholder="Ongewijzigd laten als de service account al is opgeslagen"
                  onChange={(event) => setManagedForm((current) => ({ ...current, firebaseServicePrivateKey: event.target.value }))}
                />
              </label>
            </div>
            {managedError ? <p className="error-text">{managedError}</p> : null}
            <div className="actions-row">
              <button className="primary-button" type="button" onClick={saveFirebaseSettings} disabled={saving || managedSaving}>
                {saving || managedSaving ? 'Opslaan...' : 'Firebase configuratie opslaan'}
              </button>
            </div>
          </Panel>
          <Panel title="Mobiele app tenantconfiguratie">
            {(form.publicUrl || form.apiBaseUrl).trim() !== '' ? (
              <div className="tenant-qr">
                <TotpQrCode value={normalizeWebPublicUrl(form.publicUrl || form.apiBaseUrl)} alt="QR-code met DIS server URL" helpText="Scan deze QR-code in de Android app om de server URL automatisch in te vullen." />
                <code>{normalizeWebPublicUrl(form.publicUrl || form.apiBaseUrl)}</code>
              </div>
            ) : null}
            <div className="form-grid">
              <label>
                Tenantnaam
                <input value={form.tenantName} onChange={(event) => setForm((current) => ({ ...current, tenantName: event.target.value }))} />
              </label>
              <label>
                Publieke web URL
                <input value={form.publicUrl} placeholder="https://dis.example.nl" onChange={(event) => setForm((current) => ({ ...current, publicUrl: event.target.value }))} />
              </label>
              <label>
                API URL
                <input value={form.apiBaseUrl} placeholder="https://dis.example.nl" onChange={(event) => setForm((current) => ({ ...current, apiBaseUrl: event.target.value }))} />
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

      {activeTab === 'mail' ? (
        <Panel title="Mailinstellingen">
          <div className="form-grid">
            <label>
              Mail driver
              <select value={managedForm.mailMailer} onChange={(event) => setManagedForm((current) => ({ ...current, mailMailer: event.target.value }))}>
                <option value="smtp">SMTP</option>
                <option value="microsoft365">Microsoft 365 Graph</option>
                <option value="log">Log</option>
              </select>
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
              Microsoft 365 tenant id
              <input value={managedForm.mailMicrosoft365TenantId} onChange={(event) => setManagedForm((current) => ({ ...current, mailMicrosoft365TenantId: event.target.value }))} />
            </label>
            <label>
              Microsoft 365 client id
              <input value={managedForm.mailMicrosoft365ClientId} onChange={(event) => setManagedForm((current) => ({ ...current, mailMicrosoft365ClientId: event.target.value }))} />
            </label>
            <label>
              Microsoft 365 client secret
              <input type="password" value={managedForm.mailMicrosoft365ClientSecret} placeholder="Ongewijzigd laten" onChange={(event) => setManagedForm((current) => ({ ...current, mailMicrosoft365ClientSecret: event.target.value }))} />
            </label>
            <label>
              Microsoft 365 afzender mailbox
              <input type="email" value={managedForm.mailMicrosoft365Sender} onChange={(event) => setManagedForm((current) => ({ ...current, mailMicrosoft365Sender: event.target.value }))} />
            </label>
            <label>
              Afzender e-mail
              <input value={managedForm.mailFromAddress} onChange={(event) => setManagedForm((current) => ({ ...current, mailFromAddress: event.target.value }))} />
            </label>
            <label>
              Afzender naam
              <input value={managedForm.mailFromName} onChange={(event) => setManagedForm((current) => ({ ...current, mailFromName: event.target.value }))} />
            </label>
          </div>
          {managedError ? <p className="error-text">{managedError}</p> : null}
          {managedMessage ? <p className="form-note">{managedMessage}</p> : null}
          <div className="actions-row">
            <button className="primary-button" type="button" onClick={saveMailSettings} disabled={managedSaving}>
              {managedSaving ? 'Opslaan...' : 'Mailinstellingen opslaan'}
            </button>
            <button className="secondary-button" type="button" onClick={() => void sendTestMail()} disabled={managedSaving}>
              Testmail versturen
            </button>
          </div>
        </Panel>
      ) : null}

      {activeTab === 'system' ? (
        <Panel title="Beheerbare systeeminstellingen">
          <div className="form-grid">
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

      {activeTab === 'passwords' ? (
        <Panel title="Wachtwoordeisen">
          <div className="form-grid">
            <label>
              Minimum lengte
              <input
                type="number"
                min="8"
                max="128"
                value={passwordPolicyForm.minimumLength}
                onChange={(event) => setPasswordPolicyForm((current) => ({ ...current, minimumLength: event.target.value }))}
              />
            </label>
            <label className="check-label">
              <input
                type="checkbox"
                checked={passwordPolicyForm.requiresMixedCase}
                onChange={(event) => setPasswordPolicyForm((current) => ({ ...current, requiresMixedCase: event.target.checked }))}
              />
              Hoofdletters en kleine letters verplichten
            </label>
            <label className="check-label">
              <input
                type="checkbox"
                checked={passwordPolicyForm.requiresNumbers}
                onChange={(event) => setPasswordPolicyForm((current) => ({ ...current, requiresNumbers: event.target.checked }))}
              />
              Cijfers verplichten
            </label>
            <label className="check-label">
              <input
                type="checkbox"
                checked={passwordPolicyForm.requiresSymbols}
                onChange={(event) => setPasswordPolicyForm((current) => ({ ...current, requiresSymbols: event.target.checked }))}
              />
              Symbolen verplichten
            </label>
            <label className="check-label form-grid__wide">
              <input
                type="checkbox"
                checked={passwordPolicyForm.uncompromised}
                onChange={(event) => setPasswordPolicyForm((current) => ({ ...current, uncompromised: event.target.checked }))}
              />
              Bekende gelekte wachtwoorden blokkeren
            </label>
          </div>
          {managedError ? <p className="error-text">{managedError}</p> : null}
          <div className="actions-row">
            <button className="primary-button" type="button" onClick={savePasswordPolicySettings} disabled={managedSaving}>
              {managedSaving ? 'Opslaan...' : 'Wachtwoordeisen opslaan'}
            </button>
          </div>
        </Panel>
      ) : null}

      {activeTab === 'developer' ? (
        <Panel title="Tijdelijke ontwikkeltoegang">
          <ResourceState loading={developerAccess.loading} error={developerAccess.error} empty={!developerAccess.data}>
            <div className="setup-copy">
              <strong>Android release upload via API-key</strong>
              <p>Gebruik deze tijdelijke sleutel alleen voor ontwikkel/deploy werk. De sleutel wordt eenmalig getoond; de server bewaart alleen een hash. Uitschakelen blokkeert direct uploads via deze sleutel.</p>
            </div>
            <dl className="definition-grid">
              <dt>Status</dt>
              <dd>{developerAccess.data?.enabled ? 'Ingeschakeld' : 'Uitgeschakeld'}</dd>
              <dt>Sleutel aanwezig</dt>
              <dd>{developerAccess.data?.configured ? 'Ja' : 'Nee'}</dd>
              <dt>Gegenereerd</dt>
              <dd>{formatDate(developerAccess.data?.generated_at)}</dd>
              <dt>Uitgeschakeld</dt>
              <dd>{formatDate(developerAccess.data?.disabled_at)}</dd>
              <dt>Upload endpoint</dt>
              <dd className="mono">POST /api/developer/android/upload</dd>
              <dt>Header</dt>
              <dd className="mono">X-DIS-Developer-Key</dd>
            </dl>
            {generatedDeveloperKey ? (
              <div className="metadata-example">
                <strong>Nieuwe sleutel, eenmalig zichtbaar</strong>
                <pre>{generatedDeveloperKey}</pre>
              </div>
            ) : null}
            {developerActionError ? <p className="form-error">{developerActionError}</p> : null}
            <div className="actions-row">
              <button className="secondary-button" type="button" onClick={() => void disableDeveloperKey()} disabled={developerSaving || !developerAccess.data?.enabled}>
                Ontwikkeltoegang uitzetten
              </button>
              <button className="primary-button" type="button" onClick={() => void generateDeveloperKey()} disabled={developerSaving}>
                {developerSaving ? 'Bezig...' : 'Nieuwe API-key genereren'}
              </button>
            </div>
          </ResourceState>
        </Panel>
      ) : null}

      {activeTab === 'version' ? (
        <>
          <Panel title="DIS versie">
            <ResourceState loading={systemVersion.loading} error={systemVersion.error} empty={!systemVersion.data}>
              <dl className="definition-grid">
                <dt>Applicatieversie</dt>
                <dd>{systemVersion.data?.app_version ?? '-'}</dd>
                <dt>Branch</dt>
                <dd>{systemVersion.data?.git.branch ?? '-'}</dd>
                <dt>Lokale commit</dt>
                <dd className="mono">{shortCommit(systemVersion.data?.git.current_commit)}</dd>
                <dt>Remote</dt>
                <dd>{systemVersion.data?.git.upstream ?? '-'}</dd>
                <dt>Laatste remote commit</dt>
                <dd className="mono">{shortCommit(systemVersion.data?.git.latest_commit)}</dd>
                <dt>Status</dt>
                <dd>
                  {gitUpdateStatus(systemVersion.data)}
                </dd>
                <dt>Fetch</dt>
                <dd>{systemVersion.data?.git.fetch_successful === null || systemVersion.data?.git.fetch_successful === undefined ? '-' : systemVersion.data.git.fetch_successful ? 'Gelukt' : 'Mislukt'}</dd>
              </dl>
              {(systemVersion.data?.git.errors?.length ?? 0) > 0 ? (
                <div className="metadata-example">
                  <strong>Git controle meldingen</strong>
                  <pre>{systemVersion.data?.git.errors?.join('\n')}</pre>
                </div>
              ) : null}
              {updateActionError ? <p className="form-error">{updateActionError}</p> : null}
              <div className="actions-row">
                <button className="secondary-button" type="button" onClick={() => void systemVersion.reload()}>
                  Controleer opnieuw
                </button>
                <button
                  className="primary-button"
                  type="button"
                  disabled={updateStarting || updaterStatus?.state === 'running' || !systemVersion.data?.git.update_available}
                  onClick={() => void startServerUpdate()}
                >
                  {updaterStatus?.state === 'running' ? 'Update draait...' : updateStarting ? 'Starten...' : 'Update uitvoeren'}
                </button>
              </div>
            </ResourceState>
          </Panel>
          <Panel title="Updater status">
            <dl className="definition-grid">
              <dt>Status</dt>
              <dd>{updaterStatus?.state ?? 'idle'}</dd>
              <dt>Laatste melding</dt>
              <dd>{updaterStatus?.message ?? '-'}</dd>
              <dt>Gestart</dt>
              <dd>{formatDate(updaterStatus?.started_at)}</dd>
              <dt>Afgerond</dt>
              <dd>{formatDate(updaterStatus?.finished_at)}</dd>
              <dt>Exit code</dt>
              <dd>{updaterStatus?.exit_code ?? '-'}</dd>
            </dl>
            <div className="metadata-example">
              <strong>Live log</strong>
              <pre>{(updaterStatus?.log ?? []).join('\n') || 'Nog geen updater output.'}</pre>
            </div>
          </Panel>
        </>
      ) : null}

      {activeTab === 'tokens' ? (
        <Panel title="Firebase tokens">
          {tokenActionError ? <p className="form-error">{tokenActionError}</p> : null}
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
  return formatDateTime(value);
}

function shortCommit(value?: string | null): string {
  return value !== undefined && value !== null && value !== '' ? value.slice(0, 12) : '-';
}

function gitUpdateStatus(systemVersion?: SystemVersionState | null): string {
  const git = systemVersion?.git;
  if (git === undefined) {
    return '-';
  }

  if (git.update_available) {
    return `${git.behind ?? 0} commit(s) achter`;
  }

  if (git.checkable === false) {
    return 'Update status kon niet worden gecontroleerd';
  }

  return 'Laatste versie actief';
}

function toMobileSettingsForm(settings: SystemSetting[]): MobileSettingsForm {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));
  const firebase = asRecord(byKey.get('mobile.firebase_config'));

  return {
    tenantName: asString(byKey.get('mobile.tenant_name')),
    publicUrl: asString(byKey.get('app.public_url')),
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
    mailMicrosoft365TenantId: asString(byKey.get('mail.microsoft365_tenant_id')),
    mailMicrosoft365ClientId: asString(byKey.get('mail.microsoft365_client_id')),
    mailMicrosoft365ClientSecret: '',
    mailMicrosoft365Sender: asString(byKey.get('mail.microsoft365_sender')),
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

function isFirebaseServiceAccountConfigured(settings: SystemSetting[], form: ManagedSettingsForm): boolean {
  if (form.firebaseServicePrivateKey.trim() !== '') {
    return form.firebaseServiceClientEmail.trim() !== '';
  }

  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));
  const serviceAccount = asRecord(byKey.get('firebase.service_account'));

  return asBoolean(serviceAccount.configured, false);
}

function toPasswordPolicySettingsForm(settings: SystemSetting[]): PasswordPolicySettingsForm {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));

  return {
    minimumLength: asStringOrNumber(byKey.get('security.password_min_length'), '14'),
    requiresMixedCase: asBoolean(byKey.get('security.password_requires_mixed_case'), true),
    requiresNumbers: asBoolean(byKey.get('security.password_requires_numbers'), true),
    requiresSymbols: asBoolean(byKey.get('security.password_requires_symbols'), true),
    uncompromised: asBoolean(byKey.get('security.password_uncompromised'), true),
  };
}

function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {};
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function asBoolean(value: unknown, fallback: boolean): boolean {
  return typeof value === 'boolean' ? value : fallback;
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

  return `https://${trimmed}`;
}

function normalizeWebPublicUrl(value: string): string {
  const url = new URL(normalizePublicUrl(value));
  const segments = url.pathname.split('/').filter(Boolean);
  const apiIndex = segments.indexOf('api');

  if (apiIndex >= 0) {
    url.pathname = segments.slice(0, apiIndex).length > 0 ? `/${segments.slice(0, apiIndex).join('/')}` : '/';
  }

  url.search = '';
  url.hash = '';

  return url.toString().replace(/\/+$/, '');
}

function normalizeApiBaseUrl(value: string): string {
  const publicUrl = normalizePublicUrl(value);
  const url = new URL(publicUrl);
  const segments = url.pathname.split('/').filter(Boolean);
  const apiIndex = segments.indexOf('api');

  if (apiIndex >= 0) {
    url.pathname = `/${segments.slice(0, apiIndex + 1).join('/')}`;
  } else {
    url.pathname = `${url.pathname.replace(/\/+$/, '')}/api`;
  }

  url.search = '';
  url.hash = '';

  return url.toString().replace(/\/+$/, '');
}
