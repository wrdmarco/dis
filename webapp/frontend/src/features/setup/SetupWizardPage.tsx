import { CheckCircle2, Settings, ShieldCheck } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { FirebaseSetupWizard } from '../../components/FirebaseSetupWizard';
import { TotpQrCode } from '../../components/TotpQrCode';
import { apiBaseUrl } from '../../lib/apiClient';
import type { ApiResponse, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface SetupStatus {
  setup_completed: boolean;
  has_users: boolean;
  requires_first_admin: boolean;
  public_url?: string | null;
}

interface SetupForm {
  tenantName: string;
  publicUrl: string;
  adminName: string;
  adminEmail: string;
  adminPassword: string;
  adminPasswordConfirmation: string;
  mailHost: string;
  mailPort: string;
  mailEncryption: string;
  mailUsername: string;
  mailPassword: string;
  mailFromAddress: string;
  mailFromName: string;
  firebaseProjectId: string;
  firebaseApplicationId: string;
  firebaseApiKey: string;
  firebaseMessagingSenderId: string;
  firebaseStorageBucket: string;
}

const initialForm: SetupForm = {
  tenantName: 'Nationaal Droneteam',
  publicUrl: window.location.origin,
  adminName: '',
  adminEmail: '',
  adminPassword: '',
  adminPasswordConfirmation: '',
  mailHost: '',
  mailPort: '587',
  mailEncryption: 'tls',
  mailUsername: '',
  mailPassword: '',
  mailFromAddress: '',
  mailFromName: 'Drone Inzet Systeem',
  firebaseProjectId: '',
  firebaseApplicationId: '',
  firebaseApiKey: '',
  firebaseMessagingSenderId: '',
  firebaseStorageBucket: '',
};

export function SetupWizardPage() {
  const { setSession } = useAuth();
  const [status, setStatus] = useState<SetupStatus | null>(null);
  const [form, setForm] = useState<SetupForm>(initialForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [completed, setCompleted] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function loadStatus() {
      setLoading(true);
      setError(null);
      try {
        const response = await fetch(`${apiBaseUrl}/setup/status`, { headers: { Accept: 'application/json' } });
        const payload = (await response.json()) as ApiResponse<SetupStatus>;

        if (!response.ok) {
          throw new Error('Setup status kon niet worden geladen.');
        }

        if (!cancelled) {
          setStatus(payload.data);
          setForm((current) => ({
            ...current,
            publicUrl: payload.data.public_url || window.location.origin,
          }));
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'Setup status kon niet worden geladen.');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    void loadStatus();

    return () => {
      cancelled = true;
    };
  }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    try {
      const response = await fetch(`${apiBaseUrl}/setup/complete`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          tenant_name: form.tenantName,
          public_url: normalizePublicUrl(form.publicUrl),
          admin_name: form.adminName,
          admin_email: form.adminEmail,
          admin_password: form.adminPassword,
          admin_password_confirmation: form.adminPasswordConfirmation,
          mail: {
            mailer: 'smtp',
            host: form.mailHost,
            port: Number(form.mailPort || 587),
            encryption: form.mailEncryption,
            username: form.mailUsername,
            password: form.mailPassword,
            from_address: form.mailFromAddress,
            from_name: form.mailFromName,
          },
          firebase: {
            project_id: form.firebaseProjectId,
          },
          mobile: {
            firebase_config: {
              application_id: form.firebaseApplicationId,
              api_key: form.firebaseApiKey,
              project_id: form.firebaseProjectId,
              messaging_sender_id: form.firebaseMessagingSenderId,
              storage_bucket: form.firebaseStorageBucket,
            },
          },
        }),
      });
      const payload = (await response.json().catch(() => null)) as ApiResponse<{ setup_completed: boolean; token?: string; user?: User }> | null;

      if (!response.ok) {
        throw new Error(readError(payload) ?? 'Setup opslaan mislukt.');
      }

      setCompleted(true);
      setStatus({ setup_completed: true, has_users: true, requires_first_admin: false, public_url: normalizePublicUrl(form.publicUrl) });
      if (payload?.data.token) {
        setSession(payload.data.token, payload.data.user ?? null);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Setup opslaan mislukt.');
    } finally {
      setSaving(false);
    }
  }

  const locked = status?.setup_completed || (status?.has_users && !status.requires_first_admin);

  return (
    <main className="setup-shell">
      <section className="setup-panel" aria-labelledby="setup-title">
        <div className="setup-panel__header">
          <div className="public-download-panel__mark">
            {completed || locked ? <CheckCircle2 aria-hidden size={30} /> : <Settings aria-hidden size={30} />}
          </div>
          <div>
            <span className="topbar__eyebrow">D.I.S installatie</span>
            <h1 id="setup-title">Eerste configuratie</h1>
          </div>
        </div>

        {loading ? <p className="public-download-panel__status">Setup status laden...</p> : null}
        {error ? <p className="form-error">{error}</p> : null}

        {!loading && (completed || locked) ? (
          <div className="setup-complete">
            <ShieldCheck aria-hidden size={22} />
            <div>
              <strong>Setup is afgerond.</strong>
              <p>Verdere wijzigingen beheer je via het adminpaneel.</p>
            </div>
            <Link className="primary-button" to={completed ? '/' : '/login'}>{completed ? 'Naar dashboard' : 'Naar login'}</Link>
          </div>
        ) : null}

        {!loading && !locked && !completed ? (
          <form onSubmit={submit}>
            <div className="setup-section">
              <h2>Platform</h2>
              <div className="tenant-qr">
                <TotpQrCode value={normalizePublicUrl(form.publicUrl)} alt="QR-code met DIS server URL" helpText="Scan deze QR-code in de Android app om de server URL automatisch in te vullen." />
                <code>{normalizePublicUrl(form.publicUrl)}</code>
              </div>
              <div className="form-grid">
                <label>
                  Tenantnaam
                  <input value={form.tenantName} required onChange={(event) => update('tenantName', event.target.value)} />
                </label>
                <label>
                  Publieke URL
                  <input value={form.publicUrl} required placeholder="http://dis.example.nl" onChange={(event) => update('publicUrl', event.target.value)} />
                </label>
              </div>
            </div>

            <div className="setup-section">
              <h2>Eerste beheerder</h2>
              <div className="form-grid">
                <label>
                  Naam
                  <input value={form.adminName} required onChange={(event) => update('adminName', event.target.value)} />
                </label>
                <label>
                  E-mail
                  <input type="email" value={form.adminEmail} required onChange={(event) => update('adminEmail', event.target.value)} />
                </label>
                <label>
                  Wachtwoord
                  <input type="password" value={form.adminPassword} required minLength={14} onChange={(event) => update('adminPassword', event.target.value)} />
                </label>
                <label>
                  Wachtwoord bevestigen
                  <input type="password" value={form.adminPasswordConfirmation} required minLength={14} onChange={(event) => update('adminPasswordConfirmation', event.target.value)} />
                </label>
              </div>
            </div>

            <div className="setup-section">
              <h2>Mail</h2>
              <div className="form-grid">
                <label>
                  SMTP host
                  <input value={form.mailHost} onChange={(event) => update('mailHost', event.target.value)} />
                </label>
                <label>
                  SMTP poort
                  <input type="number" min="1" value={form.mailPort} onChange={(event) => update('mailPort', event.target.value)} />
                </label>
                <label>
                  Encryptie
                  <select value={form.mailEncryption} onChange={(event) => update('mailEncryption', event.target.value)}>
                    <option value="">Geen</option>
                    <option value="tls">TLS</option>
                    <option value="ssl">SSL</option>
                  </select>
                </label>
                <label>
                  SMTP gebruiker
                  <input value={form.mailUsername} onChange={(event) => update('mailUsername', event.target.value)} />
                </label>
                <label>
                  SMTP wachtwoord
                  <input type="password" value={form.mailPassword} onChange={(event) => update('mailPassword', event.target.value)} />
                </label>
                <label>
                  Afzender e-mail
                  <input type="email" value={form.mailFromAddress} onChange={(event) => update('mailFromAddress', event.target.value)} />
                </label>
                <label>
                  Afzender naam
                  <input value={form.mailFromName} onChange={(event) => update('mailFromName', event.target.value)} />
                </label>
              </div>
            </div>

            <div className="setup-section">
              <h2>Firebase appconfiguratie</h2>
              <FirebaseSetupWizard androidApplicationId="nl.wrdmarco.dis" compact />
              <div className="form-grid">
                <label>
                  Firebase project id
                  <input value={form.firebaseProjectId} onChange={(event) => update('firebaseProjectId', event.target.value)} />
                </label>
                <label>
                  Firebase application id
                  <input value={form.firebaseApplicationId} onChange={(event) => update('firebaseApplicationId', event.target.value)} />
                </label>
                <label>
                  Firebase API key
                  <input value={form.firebaseApiKey} onChange={(event) => update('firebaseApiKey', event.target.value)} />
                </label>
                <label>
                  Firebase sender id
                  <input value={form.firebaseMessagingSenderId} onChange={(event) => update('firebaseMessagingSenderId', event.target.value)} />
                </label>
                <label>
                  Firebase storage bucket
                  <input value={form.firebaseStorageBucket} onChange={(event) => update('firebaseStorageBucket', event.target.value)} />
                </label>
              </div>
            </div>

            <div className="setup-actions">
              <button className="primary-button" type="submit" disabled={saving}>
                {saving ? 'Opslaan...' : 'Setup afronden'}
              </button>
              <Link className="secondary-button" to="/download">APK pagina</Link>
            </div>
          </form>
        ) : null}
      </section>
    </main>
  );

  function update(field: keyof SetupForm, value: string) {
    setForm((current) => ({ ...current, [field]: value }));
  }
}

function normalizePublicUrl(value: string): string {
  const trimmed = value.trim().replace(/\/+$/, '');

  if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
    return trimmed;
  }

  return `http://${trimmed}`;
}

function readError(payload: ApiResponse<unknown> | null): string | null {
  if (payload !== null && typeof payload === 'object' && 'error' in payload) {
    const error = payload.error as { message?: string };
    return error.message ?? null;
  }

  return null;
}
