import { Panel } from '../../components/Panel';
import { FirebaseSetupWizard } from '../../components/FirebaseSetupWizard';
import { ResourceState } from '../../components/ResourceState';
import { TotpQrCode } from '../../components/TotpQrCode';
import { parseFirebaseJson } from '../../lib/firebaseConfigImport';
import { formatDateTime } from '../../lib/dateTime';
import { createRealtime } from '../../lib/realtime';
import { useApiResource } from '../../lib/useApiResource';
import type { ConfigurableFormField, DeveloperAccessState, FcmToken, IncidentFormConfig, PilotReportFormConfig, PilotReportFormField, SystemSetting, SystemUpdateStatus, SystemVersionState } from '../../types/api';
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
  deviceHeartbeatIntervalMinutes: string;
  androidApplicationId: string;
  aeretMapUrl: string;
  aeretApiUrl: string;
  aeretApiKey: string;
}

interface PasswordPolicySettingsForm {
  mfaIssuerName: string;
  minimumLength: string;
  requiresMixedCase: boolean;
  requiresNumbers: boolean;
  requiresSymbols: boolean;
  uncompromised: boolean;
}

type AdminTab = 'firebase' | 'mail' | 'system' | 'passwords' | 'developer' | 'version' | 'tokens' | 'pilotReport' | 'incidentForm' | 'settings';

const adminTabs: Array<{ id: AdminTab; label: string }> = [
  { id: 'firebase', label: 'Firebase' },
  { id: 'mail', label: 'Mail' },
  { id: 'system', label: 'Systeem' },
  { id: 'passwords', label: 'Wachtwoorden' },
  { id: 'developer', label: 'Ontwikkel' },
  { id: 'version', label: 'Versie' },
  { id: 'tokens', label: 'Tokens' },
  { id: 'pilotReport', label: 'Inzetrapport' },
  { id: 'incidentForm', label: 'Incidentformulier' },
  { id: 'settings', label: 'Instellingen' },
];

const developerScopeLabels: Record<string, string> = {
  android_upload: 'Android upload',
  system_update: 'Update starten',
  logs_read: 'Logs lezen',
  user_unlock: 'Gebruiker unlocken',
};

const defaultDeveloperScopes = ['android_upload', 'system_update', 'logs_read', 'user_unlock'];

interface DeveloperKeyForm {
  scopes: string[];
  expiresAt: string;
  allowedIps: string;
}

function adminTabAllowed(
  tab: AdminTab,
  permissions: { canManageSettings: boolean; canManagePush: boolean; canViewSystemHealth: boolean },
): boolean {
  if (tab === 'tokens') {
    return permissions.canManagePush;
  }

  if (tab === 'version') {
    return permissions.canViewSystemHealth;
  }

  return permissions.canManageSettings;
}

export function AdminPage() {
  const { api, token, hasPermission } = useAuth();
  const canManageSettings = hasPermission('settings.manage');
  const canManagePush = hasPermission('push.manage');
  const canViewSystemHealth = hasPermission('system.health');
  const visibleAdminTabs = adminTabs.filter((tab) => adminTabAllowed(tab.id, { canManageSettings, canManagePush, canViewSystemHealth }));
  const settings = useApiResource<SystemSetting[]>('/admin/settings', canManageSettings);
  const tokens = useApiResource<FcmToken[]>('/admin/push/tokens?per_page=100', canManagePush);
  const developerAccess = useApiResource<DeveloperAccessState>('/admin/developer-access', canManageSettings);
  const systemVersion = useApiResource<SystemVersionState>('/admin/system/version', canViewSystemHealth);
  const pilotReportFormConfig = useApiResource<PilotReportFormConfig>('/admin/pilot-report/form-config', canManageSettings);
  const incidentFormConfig = useApiResource<IncidentFormConfig>('/admin/incident-form/config', canManageSettings);
  const mobileSettings = useMemo(() => toMobileSettingsForm(settings.data ?? []), [settings.data]);
  const managedSettings = useMemo(() => toManagedSettingsForm(settings.data ?? []), [settings.data]);
  const passwordPolicySettings = useMemo(() => toPasswordPolicySettingsForm(settings.data ?? []), [settings.data]);
  const [form, setForm] = useState<MobileSettingsForm>(mobileSettings);
  const [managedForm, setManagedForm] = useState<ManagedSettingsForm>(managedSettings);
  const [passwordPolicyForm, setPasswordPolicyForm] = useState<PasswordPolicySettingsForm>(passwordPolicySettings);
  const [pilotReportFields, setPilotReportFields] = useState<PilotReportFormField[]>([]);
  const [incidentFormFields, setIncidentFormFields] = useState<ConfigurableFormField[]>([]);
  const [activeTab, setActiveTab] = useState<AdminTab>('firebase');
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [managedSaving, setManagedSaving] = useState(false);
  const [managedError, setManagedError] = useState<string | null>(null);
  const [managedMessage, setManagedMessage] = useState<string | null>(null);
  const [tokenActionId, setTokenActionId] = useState<string | null>(null);
  const [tokenActionError, setTokenActionError] = useState<string | null>(null);
  const [developerActionError, setDeveloperActionError] = useState<string | null>(null);
  const [generatedDeveloperKey, setGeneratedDeveloperKey] = useState<string | null>(null);
  const [developerKeyForm, setDeveloperKeyForm] = useState<DeveloperKeyForm>(() => defaultDeveloperKeyForm());
  const [developerSaving, setDeveloperSaving] = useState(false);
  const [updaterStatus, setUpdaterStatus] = useState<SystemUpdateStatus | null>(null);
  const [updateActionError, setUpdateActionError] = useState<string | null>(null);
  const [updateStarting, setUpdateStarting] = useState(false);
  const [rebootStarting, setRebootStarting] = useState(false);
  const [pilotReportSaving, setPilotReportSaving] = useState(false);
  const [pilotReportMessage, setPilotReportMessage] = useState<string | null>(null);
  const [pilotReportError, setPilotReportError] = useState<string | null>(null);
  const [incidentFormSaving, setIncidentFormSaving] = useState(false);
  const [incidentFormMessage, setIncidentFormMessage] = useState<string | null>(null);
  const [incidentFormError, setIncidentFormError] = useState<string | null>(null);

  useEffect(() => {
    setForm(mobileSettings);
  }, [mobileSettings]);

  useEffect(() => {
    setManagedForm((current) => ({
      ...toManagedSettingsForm(settings.data ?? []),
      mailPassword: current.mailPassword,
      mailMicrosoft365ClientSecret: current.mailMicrosoft365ClientSecret,
      firebaseServicePrivateKey: current.firebaseServicePrivateKey,
      aeretApiKey: current.aeretApiKey,
    }));
  }, [managedSettings, settings.data]);

  useEffect(() => {
    setPasswordPolicyForm(passwordPolicySettings);
  }, [passwordPolicySettings]);

  useEffect(() => {
    setPilotReportFields(pilotReportFormConfig.data?.fields ?? []);
  }, [pilotReportFormConfig.data?.fields]);

  useEffect(() => {
    setIncidentFormFields(incidentFormConfig.data?.fields ?? []);
  }, [incidentFormConfig.data?.fields]);

  useEffect(() => {
    setUpdaterStatus(systemVersion.data?.updater ?? null);
  }, [systemVersion.data?.updater]);

  useEffect(() => {
    if (!visibleAdminTabs.some((tab) => tab.id === activeTab)) {
      setActiveTab(visibleAdminTabs[0]?.id ?? 'settings');
    }
  }, [activeTab, visibleAdminTabs]);

  const reloadSystemVersionSilently = systemVersion.silentReload;

  useEffect(() => {
    if (token === null || !canViewSystemHealth) {
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
      if (echo === null) {
        return;
      }

      echo.leave('private-admin.system');
    };
  }, [canViewSystemHealth, reloadSystemVersionSilently, token]);

  useEffect(() => {
    if (updaterStatus?.state !== 'running') {
      return;
    }

    const intervalId = window.setInterval(() => {
      void reloadSystemVersionSilently();
    }, 5000);

    return () => window.clearInterval(intervalId);
  }, [reloadSystemVersionSilently, updaterStatus?.state]);

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
        'devices.heartbeat_interval_minutes': Number(managedForm.deviceHeartbeatIntervalMinutes || 15),
        'updates.android.application_id': managedForm.androidApplicationId,
        'drone.aeret_map_url': managedForm.aeretMapUrl.trim() === '' ? null : managedForm.aeretMapUrl,
        'drone.aeret_api_url': managedForm.aeretApiUrl.trim() === '' ? null : managedForm.aeretApiUrl,
      };

      if (managedForm.aeretApiKey.trim() !== '') {
        payload['drone.aeret_api_key'] = managedForm.aeretApiKey;
      }

      await api.patch('/admin/settings', { settings: payload });
      setManagedForm((current) => ({ ...current, aeretApiKey: '' }));
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
    setManagedMessage(null);
    try {
      const payload: Record<string, unknown> = {
        'mail.mailer': managedForm.mailMailer,
      };

      if (managedForm.mailMailer === 'microsoft365') {
        payload['mail.microsoft365_tenant_id'] = managedForm.mailMicrosoft365TenantId;
        payload['mail.microsoft365_client_id'] = managedForm.mailMicrosoft365ClientId;
        payload['mail.microsoft365_sender'] = managedForm.mailMicrosoft365Sender;
        if (managedForm.mailMicrosoft365ClientSecret.trim() !== '') {
          payload['mail.microsoft365_client_secret'] = managedForm.mailMicrosoft365ClientSecret;
        }
      }

      if (managedForm.mailMailer === 'smtp') {
        payload['mail.host'] = managedForm.mailHost;
        payload['mail.port'] = Number(managedForm.mailPort || 587);
        payload['mail.encryption'] = managedForm.mailEncryption;
        payload['mail.username'] = managedForm.mailUsername;
        payload['mail.from_address'] = managedForm.mailFromAddress;
        payload['mail.from_name'] = managedForm.mailFromName;
        if (managedForm.mailPassword.trim() !== '') {
          payload['mail.password'] = managedForm.mailPassword;
        }
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
          'security.mfa_issuer_name': passwordPolicyForm.mfaIssuerName.trim() || 'D.I.S',
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

  async function generateDeveloperKey() {
    setDeveloperSaving(true);
    setDeveloperActionError(null);
    setGeneratedDeveloperKey(null);
    if (developerKeyForm.scopes.length === 0) {
      setDeveloperActionError('Kies minimaal een recht voor deze sleutel.');
      setDeveloperSaving(false);
      return;
    }
    try {
      const response = await api.post<DeveloperAccessState>('/admin/developer-access/key', {
        scopes: developerKeyForm.scopes,
        expires_at: developerKeyForm.expiresAt || undefined,
        allowed_ips: splitDeveloperAllowedIps(developerKeyForm.allowedIps),
      });
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

  async function startServerUpdate(updateSystem: boolean) {
    setUpdateStarting(true);
    setUpdateActionError(null);
    try {
      const response = await api.post<SystemUpdateStatus>('/admin/system/update', { update_system: updateSystem });
      setUpdaterStatus(response.data);
      await systemVersion.silentReload();
    } catch (error) {
      setUpdateActionError(error instanceof ApiClientError ? error.message : 'Update starten mislukt.');
    } finally {
      setUpdateStarting(false);
    }
  }

  async function rebootServer() {
    setRebootStarting(true);
    setUpdateActionError(null);
    try {
      await api.post<{ reboot_started: boolean }>('/admin/system/reboot');
      setUpdaterStatus((current) => ({
        ...(current ?? { state: 'idle' as const }),
        message: 'Serverherstart gestart.',
        log: [...(current?.log ?? []), 'Serverherstart gestart.'],
      }));
    } catch (error) {
      setUpdateActionError(error instanceof ApiClientError ? error.message : 'Serverherstart starten mislukt.');
    } finally {
      setRebootStarting(false);
    }
  }

  async function savePilotReportFormConfig() {
    setPilotReportSaving(true);
    setPilotReportError(null);
    setPilotReportMessage(null);
    try {
      const response = await api.patch<PilotReportFormConfig>('/admin/pilot-report/form-config', {
        fields: pilotReportFields,
      });
      setPilotReportFields(response.data.fields);
      setPilotReportMessage('Inzetrapport formulier is opgeslagen.');
      await pilotReportFormConfig.reload();
    } catch (error) {
      setPilotReportError(error instanceof ApiClientError ? error.message : 'Inzetrapport formulier opslaan mislukt.');
    } finally {
      setPilotReportSaving(false);
    }
  }

  async function saveIncidentFormConfig() {
    setIncidentFormSaving(true);
    setIncidentFormError(null);
    setIncidentFormMessage(null);
    try {
      const response = await api.patch<IncidentFormConfig>('/admin/incident-form/config', {
        fields: incidentFormFields,
      });
      setIncidentFormFields(response.data.fields);
      setIncidentFormMessage('Incidentformulier is opgeslagen.');
      await incidentFormConfig.reload();
    } catch (error) {
      setIncidentFormError(error instanceof ApiClientError ? error.message : 'Incidentformulier opslaan mislukt.');
    } finally {
      setIncidentFormSaving(false);
    }
  }

  function updatePilotReportField(key: string, changes: Partial<PilotReportFormField>) {
    setPilotReportFields((current) => current.map((field) => {
      if (field.key !== key) {
        return field;
      }

      const next = { ...field, ...changes };
      return next.visible ? next : { ...next, required: false };
    }));
  }

  function addPilotReportField() {
    setPilotReportFields((current) => [...current, newCustomFormField(current)]);
  }

  function removePilotReportField(key: string) {
    setPilotReportFields((current) => current.filter((field) => field.key !== key));
  }

  function movePilotReportField(key: string, direction: -1 | 1) {
    setPilotReportFields((current) => moveFormField(current, key, direction));
  }

  function updateIncidentFormField(key: string, changes: Partial<ConfigurableFormField>) {
    setIncidentFormFields((current) => current.map((field) => {
      if (field.key !== key) {
        return field;
      }

      const next = { ...field, ...changes };
      return next.visible ? next : { ...next, required: false };
    }));
  }

  function addIncidentFormField() {
    setIncidentFormFields((current) => [...current, newCustomFormField(current)]);
  }

  function removeIncidentFormField(key: string) {
    setIncidentFormFields((current) => current.filter((field) => field.key !== key));
  }

  function moveIncidentFormField(key: string, direction: -1 | 1) {
    setIncidentFormFields((current) => moveFormField(current, key, direction));
  }

  return (
    <div className="page-stack">
      <div className="admin-tabs" role="tablist" aria-label="Admin onderdelen">
        {visibleAdminTabs.map((tab) => (
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
          <div className="setup-copy">
            <strong>Mailroute</strong>
            <p>Kies SMTP, loggen of de Entra app. Voor Microsoft 365 gebruikt D.I.S de Entra app met Graph Mail.Send application permission.</p>
            <p>Entra app: {isMicrosoft365MailConfigured(settings.data ?? [], managedForm) ? 'compleet ingesteld' : 'nog niet compleet'}</p>
          </div>
          <div className="form-grid">
            <label>
              Mail driver
              <select value={managedForm.mailMailer} onChange={(event) => setManagedForm((current) => ({ ...current, mailMailer: event.target.value }))}>
                <option value="smtp">SMTP</option>
                <option value="microsoft365">Entra app - Microsoft Graph</option>
                <option value="log">Log</option>
              </select>
            </label>
          </div>

          {managedForm.mailMailer === 'microsoft365' ? (
            <div className="settings-group">
              <h3>Entra app voor mail</h3>
              <div className="metadata-example">
                <strong>Vereist in Entra</strong>
                <pre>Microsoft Graph: Mail.Send als Application permission met admin consent.</pre>
              </div>
              <dl className="definition-grid">
                <dt>Client secret</dt>
                <dd>{isMicrosoft365ClientSecretConfigured(settings.data ?? []) ? 'Ingesteld' : 'Niet ingesteld'}</dd>
                <dt>Token endpoint</dt>
                <dd className="mono">https://login.microsoftonline.com/&lt;tenant&gt;/oauth2/v2.0/token</dd>
                <dt>Graph actie</dt>
                <dd className="mono">POST /users/&lt;mailbox&gt;/sendMail</dd>
              </dl>
              <div className="form-grid">
                <label>
                  Tenant id
                  <input value={managedForm.mailMicrosoft365TenantId} onChange={(event) => setManagedForm((current) => ({ ...current, mailMicrosoft365TenantId: event.target.value }))} />
                </label>
                <label>
                  Application client id
                  <input value={managedForm.mailMicrosoft365ClientId} onChange={(event) => setManagedForm((current) => ({ ...current, mailMicrosoft365ClientId: event.target.value }))} />
                </label>
                <label>
                  Client secret
                  <input type="password" value={managedForm.mailMicrosoft365ClientSecret} placeholder="Ongewijzigd laten" onChange={(event) => setManagedForm((current) => ({ ...current, mailMicrosoft365ClientSecret: event.target.value }))} />
                </label>
                <label>
                  Afzender mailbox
                  <input type="email" value={managedForm.mailMicrosoft365Sender} onChange={(event) => setManagedForm((current) => ({ ...current, mailMicrosoft365Sender: event.target.value }))} />
                </label>
              </div>
            </div>
          ) : null}

          {managedForm.mailMailer === 'smtp' ? (
            <div className="settings-group">
              <h3>SMTP</h3>
              <div className="form-grid">
                <label>
                  Afzender e-mail
                  <input type="email" value={managedForm.mailFromAddress} onChange={(event) => setManagedForm((current) => ({ ...current, mailFromAddress: event.target.value }))} />
                </label>
                <label>
                  Afzender naam
                  <input value={managedForm.mailFromName} onChange={(event) => setManagedForm((current) => ({ ...current, mailFromName: event.target.value }))} />
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
              </div>
            </div>
          ) : null}
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
            <label>
              Operator heartbeat minuten
              <input type="number" min="1" max="60" value={managedForm.deviceHeartbeatIntervalMinutes} onChange={(event) => setManagedForm((current) => ({ ...current, deviceHeartbeatIntervalMinutes: event.target.value }))} />
              <small>Standaard 15. Offline na ongeveer 2x dit interval.</small>
            </label>
            <label className="form-grid__wide">
              Aeret dronekaart URL
              <input value={managedForm.aeretMapUrl} placeholder="https://aeret.kaartviewer.nl/?@dpf_basic" onChange={(event) => setManagedForm((current) => ({ ...current, aeretMapUrl: event.target.value }))} />
            </label>
            <label className="form-grid__wide">
              Aeret API endpoint
              <input value={managedForm.aeretApiUrl} placeholder="https://..." onChange={(event) => setManagedForm((current) => ({ ...current, aeretApiUrl: event.target.value }))} />
            </label>
            <label className="form-grid__wide">
              Aeret API key
              <input type="password" value={managedForm.aeretApiKey} placeholder="Ongewijzigd laten" onChange={(event) => setManagedForm((current) => ({ ...current, aeretApiKey: event.target.value }))} />
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
        <Panel title="MFA en wachtwoordeisen">
          <div className="form-grid">
            <label>
              Authenticator naam
              <input
                maxLength={64}
                value={passwordPolicyForm.mfaIssuerName}
                onChange={(event) => setPasswordPolicyForm((current) => ({ ...current, mfaIssuerName: event.target.value }))}
              />
            </label>
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
              {managedSaving ? 'Opslaan...' : 'MFA en wachtwoordeisen opslaan'}
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
              <dt>Verlopen</dt>
              <dd>{developerAccess.data?.expired ? 'Ja' : 'Nee'}</dd>
              <dt>Sleutel aanwezig</dt>
              <dd>{developerAccess.data?.configured ? 'Ja' : 'Nee'}</dd>
              <dt>Rechten</dt>
              <dd>{formatDeveloperScopes(developerAccess.data?.scopes)}</dd>
              <dt>Vervalt op</dt>
              <dd>{formatDate(developerAccess.data?.expires_at)}</dd>
              <dt>IP beperking</dt>
              <dd>{formatDeveloperAllowedIps(developerAccess.data?.allowed_ips)}</dd>
              <dt>Gegenereerd</dt>
              <dd>{formatDate(developerAccess.data?.generated_at)}</dd>
              <dt>Uitgeschakeld</dt>
              <dd>{formatDate(developerAccess.data?.disabled_at)}</dd>
              <dt>Upload endpoint</dt>
              <dd className="mono">POST /api/developer/android/upload</dd>
              <dt>Update endpoint</dt>
              <dd className="mono">POST /api/developer/system/update</dd>
              <dt>Logs endpoint</dt>
              <dd className="mono">GET /api/developer/logs</dd>
              <dt>Unlock endpoint</dt>
              <dd className="mono">POST /api/developer/users/login-lock/reset</dd>
              <dt>Header</dt>
              <dd className="mono">X-DIS-Developer-Key</dd>
            </dl>
            {developerAccess.data?.legacy_unscoped ? (
              <p className="form-note">Deze bestaande sleutel heeft nog geen opgeslagen rechten. Maak een nieuwe sleutel aan om rechten, vervaldatum en IP-beperking vast te leggen.</p>
            ) : null}
            <div className="form-grid">
              <div className="form-grid__wide">
                <span className="field-label">Rechten voor nieuwe sleutel</span>
                {(developerAccess.data?.available_scopes ?? defaultDeveloperScopes).map((scope) => (
                  <label className="check-label" key={scope}>
                    <input
                      type="checkbox"
                      checked={developerKeyForm.scopes.includes(scope)}
                      onChange={(event) => setDeveloperKeyForm((current) => ({
                        ...current,
                        scopes: event.target.checked
                          ? Array.from(new Set([...current.scopes, scope]))
                          : current.scopes.filter((value) => value !== scope),
                      }))}
                    />
                    {developerScopeLabels[scope] ?? scope}
                  </label>
                ))}
              </div>
              <label>
                Vervaldatum
                <input
                  type="date"
                  value={developerKeyForm.expiresAt}
                  onChange={(event) => setDeveloperKeyForm((current) => ({ ...current, expiresAt: event.target.value }))}
                />
              </label>
              <label className="form-grid__wide">
                Toegestane IP-adressen
                <textarea
                  rows={3}
                  value={developerKeyForm.allowedIps}
                  onChange={(event) => setDeveloperKeyForm((current) => ({ ...current, allowedIps: event.target.value }))}
                  placeholder="Leeg laten voor geen IP-beperking. Een IP of CIDR per regel."
                />
              </label>
            </div>
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
                <dt>Herstart nodig</dt>
                <dd>{(updaterStatus?.reboot_required ?? systemVersion.data?.system?.reboot_required) ? 'Ja' : 'Nee'}</dd>
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
                  disabled={updateStarting || updaterStatus?.state === 'running' || systemVersion.data === null}
                  onClick={() => void startServerUpdate(false)}
                >
                  {updaterStatus?.state === 'running' ? 'Update draait...' : updateStarting ? 'Starten...' : 'App update'}
                </button>
                <button
                  className="primary-button"
                  type="button"
                  disabled={updateStarting || updaterStatus?.state === 'running' || systemVersion.data === null}
                  onClick={() => void startServerUpdate(true)}
                >
                  {updaterStatus?.state === 'running' ? 'Update draait...' : updateStarting ? 'Starten...' : 'Systeem update'}
                </button>
                <button
                  className="secondary-button"
                  type="button"
                  disabled={rebootStarting || updaterStatus?.state === 'running' || !(updaterStatus?.reboot_required ?? systemVersion.data?.system?.reboot_required)}
                  onClick={() => void rebootServer()}
                >
                  {rebootStarting ? 'Herstarten...' : 'Server herstarten'}
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

      {activeTab === 'pilotReport' ? (
        <Panel title="Inzetrapport formulier">
          <ResourceState loading={pilotReportFormConfig.loading} error={pilotReportFormConfig.error} empty={pilotReportFields.length === 0}>
            <ConfigurableFormEditor
              fields={pilotReportFields}
              description="Bouw het inzetrapport volledig op uit variabele velden. Oude vaste rapportkolommen blijven alleen bestaan voor historische rapporten."
              onAdd={addPilotReportField}
              onMove={movePilotReportField}
              onRemove={removePilotReportField}
              onUpdate={updatePilotReportField}
            />
            {pilotReportError ? <p className="form-error">{pilotReportError}</p> : null}
            {pilotReportMessage ? <p className="success-text">{pilotReportMessage}</p> : null}
            <div className="actions-row">
              <button className="primary-button" type="button" onClick={() => void savePilotReportFormConfig()} disabled={pilotReportSaving}>
                {pilotReportSaving ? 'Opslaan...' : 'Formulier opslaan'}
              </button>
            </div>
          </ResourceState>
        </Panel>
      ) : null}

      {activeTab === 'incidentForm' ? (
        <Panel title="Incidentformulier">
          <ResourceState loading={incidentFormConfig.loading} error={incidentFormConfig.error} empty={false}>
            <ConfigurableFormEditor
              fields={incidentFormFields}
              description="Beheer variabele velden voor het incident-aanmaakformulier. Operationele kerngegevens blijven onderdeel van de incidentflow."
              onAdd={addIncidentFormField}
              onMove={moveIncidentFormField}
              onRemove={removeIncidentFormField}
              onUpdate={updateIncidentFormField}
            />
            {incidentFormError ? <p className="form-error">{incidentFormError}</p> : null}
            {incidentFormMessage ? <p className="success-text">{incidentFormMessage}</p> : null}
            <div className="actions-row">
              <button className="primary-button" type="button" onClick={() => void saveIncidentFormConfig()} disabled={incidentFormSaving}>
                {incidentFormSaving ? 'Opslaan...' : 'Formulier opslaan'}
              </button>
            </div>
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

function ConfigurableFormEditor(props: {
  fields: ConfigurableFormField[];
  description: string;
  onAdd: () => void;
  onMove?: (key: string, direction: -1 | 1) => void;
  onRemove: (key: string) => void;
  onUpdate: (key: string, changes: Partial<ConfigurableFormField>) => void;
}) {
  const { fields, description, onAdd, onMove, onRemove, onUpdate } = props;

  return (
    <div className="page-stack">
      <p className="form-note">{description}</p>
      <div className="actions-row">
        <button className="secondary-button" type="button" onClick={onAdd}>Extra veld toevoegen</button>
      </div>
      <table className="data-table">
        <thead><tr><th>Volgorde</th><th>Label</th><th>Type</th><th>Zichtbaar</th><th>Verplicht</th><th>Opties</th><th></th></tr></thead>
        <tbody>
          {fields.map((field, index) => (
              <tr key={field.key}>
                <td>
                  <div className="actions-row">
                    <button className="secondary-button" type="button" disabled={index === 0} onClick={() => onMove?.(field.key, -1)}>Omhoog</button>
                    <button className="secondary-button" type="button" disabled={index === fields.length - 1} onClick={() => onMove?.(field.key, 1)}>Omlaag</button>
                  </div>
                </td>
                <td>
                  <input type="hidden" value={field.key} readOnly />
                  <input
                    value={field.label}
                    onChange={(event) => {
                      const label = event.target.value;
                      onUpdate(field.key, { label, key: generateFieldKey(label, fields, field.key) });
                    }}
                  />
                </td>
                <td>
                  <select
                    value={field.type}
                    onChange={(event) => onUpdate(field.key, {
                      type: event.target.value as ConfigurableFormField['type'],
                      option_source: 'manual',
                      options: ['select', 'radio'].includes(event.target.value) ? defaultFieldOptions(field.options) : [],
                    })}
                  >
                    <option value="text">Tekst</option>
                    <option value="textarea">Grote tekst</option>
                    <option value="number">Getal</option>
                    <option value="select">Dropdown</option>
                    <option value="checkbox">Checkbox</option>
                    <option value="radio">Radioknoppen</option>
                  </select>
                </td>
                <td>
                  <input type="checkbox" checked={field.visible} onChange={(event) => onUpdate(field.key, { visible: event.target.checked })} />
                </td>
                <td>
                  <input type="checkbox" checked={field.required} disabled={!field.visible} onChange={(event) => onUpdate(field.key, { required: event.target.checked })} />
                </td>
                <td>
                  {['select', 'radio'].includes(field.type) ? (
                    <div className="page-stack">
                      <select
                        value={field.option_source ?? 'manual'}
                        onChange={(event) => onUpdate(field.key, {
                          option_source: event.target.value as ConfigurableFormField['option_source'],
                          options: event.target.value === 'manual' ? defaultFieldOptions(field.options) : [],
                        })}
                      >
                        <option value="manual">Handmatige opties</option>
                        <option value="user_drones">Drones van gebruiker</option>
                      </select>
                      {(field.option_source ?? 'manual') === 'manual' ? (
                        <textarea
                          value={(field.options ?? []).map((option) => option.label).join('\n')}
                          rows={3}
                          onChange={(event) => onUpdate(field.key, { options: optionsFromTextarea(event.target.value) })}
                        />
                      ) : (
                        <span className="muted-text">Wordt in de app gevuld met actief gekoppelde drones van de piloot.</span>
                      )}
                    </div>
                  ) : (
                    <span className="muted-text">Niet nodig</span>
                  )}
                </td>
                <td>
                  <button className="secondary-button" type="button" onClick={() => onRemove(field.key)}>Verwijderen</button>
                </td>
              </tr>
          ))}
        </tbody>
      </table>
      {fields.length === 0 ? <p className="muted-text">Nog geen variabele velden ingesteld.</p> : null}
      <p className="form-note">Veldsleutels worden automatisch uit het label gemaakt. Opties voor dropdown en radio: een optie per regel.</p>
    </div>
  );
}

function newCustomFormField(fields: ConfigurableFormField[]): ConfigurableFormField {
  let index = fields.length + 1;
  let key = `veld_${index}`;
  while (fields.some((field) => field.key === key)) {
    index += 1;
    key = `veld_${index}`;
  }

  return {
    key,
    label: 'Nieuw veld',
    type: 'text',
    visible: true,
    required: false,
    option_source: 'manual',
    options: [],
    is_custom: true,
  };
}

function moveFormField<T extends ConfigurableFormField>(fields: T[], key: string, direction: -1 | 1): T[] {
  const index = fields.findIndex((field) => field.key === key);
  const nextIndex = index + direction;
  if (index < 0 || nextIndex < 0 || nextIndex >= fields.length) {
    return fields;
  }

  const next = [...fields];
  const [field] = next.splice(index, 1);
  next.splice(nextIndex, 0, field);
  return next;
}

function generateFieldKey(label: string, fields: ConfigurableFormField[], currentKey?: string): string {
  const normalized = label
    .toLowerCase()
    .replace(/[^a-z0-9_]/g, '_')
    .replace(/^_+/, '')
    .replace(/_+/g, '_')
    .replace(/_+$/, '');
  const base = /^[a-z]/.test(normalized) && normalized.length >= 2 ? normalized : 'veld';
  let key = base.slice(0, 60);
  let index = 2;
  while (fields.some((field) => field.key !== currentKey && field.key === key)) {
    const suffix = `_${index}`;
    key = `${base.slice(0, 60 - suffix.length)}${suffix}`;
    index += 1;
  }
  return key;
}

function defaultFieldOptions(options?: Array<{ label: string; value: string }>): Array<{ label: string; value: string }> {
  return options !== undefined && options.length >= 2 ? options : [
    { label: 'Optie 1', value: 'Optie 1' },
    { label: 'Optie 2', value: 'Optie 2' },
  ];
}

function optionsFromTextarea(value: string): Array<{ label: string; value: string }> {
  return value
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => ({ label: line, value: line }));
}

function formatDate(value?: string | null): string {
  return formatDateTime(value);
}

function defaultDeveloperKeyForm(): DeveloperKeyForm {
  const expiresAt = new Date();
  expiresAt.setDate(expiresAt.getDate() + 30);

  return {
    scopes: [...defaultDeveloperScopes],
    expiresAt: expiresAt.toISOString().slice(0, 10),
    allowedIps: '',
  };
}

function splitDeveloperAllowedIps(value: string): string[] {
  return value
    .split(/[\n,]+/)
    .map((item) => item.trim())
    .filter(Boolean);
}

function formatDeveloperScopes(scopes?: string[]): string {
  if (scopes === undefined || scopes.length === 0) {
    return '-';
  }

  return scopes.map((scope) => developerScopeLabels[scope] ?? scope).join(', ');
}

function formatDeveloperAllowedIps(allowedIps?: string[]): string {
  if (allowedIps === undefined || allowedIps.length === 0) {
    return 'Geen beperking';
  }

  return allowedIps.join(', ');
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
    deviceHeartbeatIntervalMinutes: asStringOrNumber(byKey.get('devices.heartbeat_interval_minutes'), '15'),
    androidApplicationId: asString(byKey.get('updates.android.application_id')) || 'nl.wrdmarco.dis',
    aeretMapUrl: asString(byKey.get('drone.aeret_map_url')) || 'https://aeret.kaartviewer.nl/?@dpf_basic',
    aeretApiUrl: asString(byKey.get('drone.aeret_api_url')),
    aeretApiKey: '',
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

function isMicrosoft365MailConfigured(settings: SystemSetting[], form: ManagedSettingsForm): boolean {
  return form.mailMicrosoft365TenantId.trim() !== ''
    && form.mailMicrosoft365ClientId.trim() !== ''
    && form.mailMicrosoft365Sender.trim() !== ''
    && (form.mailMicrosoft365ClientSecret.trim() !== '' || isMicrosoft365ClientSecretConfigured(settings));
}

function isMicrosoft365ClientSecretConfigured(settings: SystemSetting[]): boolean {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));
  const secret = asRecord(byKey.get('mail.microsoft365_client_secret'));

  return asBoolean(secret.configured, false);
}

function toPasswordPolicySettingsForm(settings: SystemSetting[]): PasswordPolicySettingsForm {
  const byKey = new Map(settings.map((setting) => [setting.key, setting.value]));

  return {
    mfaIssuerName: asString(byKey.get('security.mfa_issuer_name')) || 'D.I.S',
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
