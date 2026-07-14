import { CheckCircle2, ChevronLeft, ChevronRight, Mail, Rocket, Settings, ShieldCheck, Smartphone, UserRound } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { FirebaseSetupWizard } from '../../components/FirebaseSetupWizard';
import { TotpQrCode } from '../../components/TotpQrCode';
import { parseFirebaseJson } from '../../lib/firebaseConfigImport';
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
  firebaseServiceClientEmail: string;
  firebaseServicePrivateKey: string;
  firebaseServicePrivateKeyId: string;
  firebaseServiceClientId: string;
  firebaseServiceClientX509CertUrl: string;
}

const initialForm: SetupForm = {
  tenantName: 'Nationaal Droneteam',
  publicUrl: '',
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
  firebaseServiceClientEmail: '',
  firebaseServicePrivateKey: '',
  firebaseServicePrivateKeyId: '',
  firebaseServiceClientId: '',
  firebaseServiceClientX509CertUrl: '',
};

const steps = [
  { key: 'platform', title: 'Platform', icon: Settings },
  { key: 'admin', title: 'Beheerder', icon: UserRound },
  { key: 'mail', title: 'Mail', icon: Mail },
  { key: 'mobile', title: 'Mobiele app', icon: Smartphone },
  { key: 'service', title: 'Service account', icon: ShieldCheck },
  { key: 'review', title: 'Controle', icon: Rocket },
] as const;

export function SetupWizardPage() {
  const { api } = useAuth();
  const [status, setStatus] = useState<SetupStatus | null>(null);
  const [form, setForm] = useState<SetupForm>(initialForm);
  const [stepIndex, setStepIndex] = useState(0);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [completed, setCompleted] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const currentStep = steps[stepIndex];
  const stepError = useMemo(() => validateStep(stepIndex, form), [form, stepIndex]);

  useEffect(() => {
    let cancelled = false;

    async function loadStatus() {
      setLoading(true);
      setError(null);
      try {
        const response = await api.get<SetupStatus>('/setup/status');

        if (!cancelled) {
          setStatus(response.data);
          setForm((current) => ({
            ...current,
            publicUrl: response.data.public_url || window.location.origin,
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
  }, [api]);

  useEffect(() => {
    setForm((current) => current.publicUrl ? current : { ...current, publicUrl: window.location.origin });
  }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    const validation = validateAll(form);
    if (validation !== null) {
      setError(validation);
      return;
    }

    setSaving(true);
    setError(null);

    try {
      await api.post<{ setup_completed: boolean }>('/setup/complete', {
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
            service_account: {
              client_email: form.firebaseServiceClientEmail,
              private_key: form.firebaseServicePrivateKey,
              private_key_id: form.firebaseServicePrivateKeyId,
              client_id: form.firebaseServiceClientId,
              client_x509_cert_url: form.firebaseServiceClientX509CertUrl,
            },
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
      });

      setCompleted(true);
      setStatus({ setup_completed: true, has_users: true, requires_first_admin: false, public_url: normalizePublicUrl(form.publicUrl) });
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

        {loading ? <p className="public-download-panel__status setup-status-line">Setup status laden...</p> : null}
        {error ? <p className="form-error setup-status-line">{error}</p> : null}

        {!loading && (completed || locked) ? (
          <div className="setup-complete">
            <ShieldCheck aria-hidden size={22} />
            <div>
              <strong>Setup is afgerond.</strong>
              <p>Verdere wijzigingen beheer je via het adminpaneel.</p>
            </div>
            <Link className="primary-button" href="/login">Naar login</Link>
          </div>
        ) : null}

        {!loading && !locked && !completed ? (
          <form onSubmit={submit}>
            <nav className="setup-stepper" aria-label="Setup stappen">
              {steps.map((step, index) => {
                const Icon = step.icon;
                return (
                  <button
                    key={step.key}
                    type="button"
                    className={index === stepIndex ? 'setup-stepper__item setup-stepper__item--active' : 'setup-stepper__item'}
                    onClick={() => setStepIndex(index)}
                  >
                    <span>{index + 1}</span>
                    <Icon size={16} />
                    <strong>{step.title}</strong>
                  </button>
                );
              })}
            </nav>

            <div className="setup-section setup-section--active">
              <h2>{currentStep.title}</h2>
              {renderStep()}
            </div>

            {stepError ? <p className="form-hint setup-status-line">{stepError}</p> : null}

            <div className="setup-actions">
              <button className="secondary-button" type="button" onClick={previousStep} disabled={stepIndex === 0 || saving}>
                <ChevronLeft size={16} /> Vorige
              </button>
              {stepIndex < steps.length - 1 ? (
                <button className="primary-button" type="button" onClick={nextStep} disabled={saving || stepError !== null}>
                  Volgende <ChevronRight size={16} />
                </button>
              ) : (
                <button className="primary-button" type="submit" disabled={saving || validateAll(form) !== null}>
                  {saving ? 'Opslaan...' : 'Setup afronden'}
                </button>
              )}
            </div>
          </form>
        ) : null}
      </section>
    </main>
  );

  function renderStep() {
    if (currentStep.key === 'platform') {
      return (
        <>
          <div className="setup-copy">
            <strong>Basisgegevens voor webapp en Android app.</strong>
            <p>De Android app gebruikt alleen de server URL. De app voegt zelf `/api` toe.</p>
          </div>
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
              <input value={form.publicUrl} required placeholder="https://dis.example.nl" onChange={(event) => update('publicUrl', event.target.value)} />
            </label>
          </div>
        </>
      );
    }

    if (currentStep.key === 'admin') {
      return (
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
      );
    }

    if (currentStep.key === 'mail') {
      return (
        <>
          <div className="setup-copy">
            <strong>Mail is optioneel tijdens installatie.</strong>
            <p>Je kunt deze waarden later wijzigen in het adminpaneel.</p>
          </div>
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
        </>
      );
    }

    if (currentStep.key === 'mobile') {
      return (
        <>
          <FirebaseSetupWizard androidApplicationId="nl.wrdmarco.dis" compact />
          <div className="setup-copy">
            <strong>Firebase JSON automatisch uitlezen.</strong>
            <p>Kies je Firebase JSON-bestand. DIS vult de waarden in die in dit bestand aanwezig zijn.</p>
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
        </>
      );
    }

    if (currentStep.key === 'service') {
      return (
        <>
          <div className="setup-copy">
            <strong>Alles via deze webpagina.</strong>
            <p>Kies je service-account JSON-bestand. DIS vult de velden automatisch; er hoeft niets handmatig op de server geplaatst te worden.</p>
          </div>
          <div className="form-grid">
            <label className="form-grid__wide">
              Firebase service-account JSON
              <input
                accept="application/json,.json"
                type="file"
                onChange={(event) => {
                  void importFirebaseJson(event.currentTarget.files?.[0] ?? null);
                  event.currentTarget.value = '';
                }}
              />
            </label>
            <label>
              Service account client_email
              <input value={form.firebaseServiceClientEmail} onChange={(event) => update('firebaseServiceClientEmail', event.target.value)} />
            </label>
            <label>
              Service account private_key_id
              <input value={form.firebaseServicePrivateKeyId} onChange={(event) => update('firebaseServicePrivateKeyId', event.target.value)} />
            </label>
            <label>
              Service account client_id
              <input value={form.firebaseServiceClientId} onChange={(event) => update('firebaseServiceClientId', event.target.value)} />
            </label>
            <label>
              Service account cert URL
              <input value={form.firebaseServiceClientX509CertUrl} onChange={(event) => update('firebaseServiceClientX509CertUrl', event.target.value)} />
            </label>
            <label className="form-grid__wide">
              Service account private_key
              <textarea className="mono" rows={8} value={form.firebaseServicePrivateKey} onChange={(event) => update('firebaseServicePrivateKey', event.target.value)} />
            </label>
          </div>
        </>
      );
    }

    return (
      <div className="setup-review">
        <div><span>Tenant</span><strong>{form.tenantName}</strong></div>
        <div><span>Publieke URL</span><strong>{normalizePublicUrl(form.publicUrl)}</strong></div>
        <div><span>Eerste beheerder</span><strong>{form.adminEmail}</strong></div>
        <div><span>Mail</span><strong>{form.mailHost.trim() ? `${form.mailHost}:${form.mailPort}` : 'Later configureren'}</strong></div>
        <div><span>Firebase mobiele app</span><strong>{form.firebaseProjectId.trim() ? 'Ingevuld' : 'Later configureren'}</strong></div>
        <div><span>Firebase service account</span><strong>{form.firebaseServiceClientEmail.trim() ? 'Ingevuld via webpagina' : 'Later configureren'}</strong></div>
      </div>
    );
  }

  function nextStep() {
    if (stepError !== null) {
      setError(stepError);
      return;
    }
    setError(null);
    setStepIndex((current) => Math.min(current + 1, steps.length - 1));
  }

  function previousStep() {
    setError(null);
    setStepIndex((current) => Math.max(current - 1, 0));
  }

  function update(field: keyof SetupForm, value: string) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function importFirebaseJson(file: File | null) {
    if (file === null) {
      return;
    }

    setError(null);

    try {
      const imported = parseFirebaseJson(await file.text());
      setForm((current) => ({
        ...current,
        firebaseProjectId: imported.projectId ?? current.firebaseProjectId,
        firebaseApplicationId: imported.applicationId ?? current.firebaseApplicationId,
        firebaseApiKey: imported.apiKey ?? current.firebaseApiKey,
        firebaseMessagingSenderId: imported.messagingSenderId ?? current.firebaseMessagingSenderId,
        firebaseStorageBucket: imported.storageBucket ?? current.firebaseStorageBucket,
        firebaseServiceClientEmail: imported.serviceAccount?.clientEmail ?? current.firebaseServiceClientEmail,
        firebaseServicePrivateKey: imported.serviceAccount?.privateKey ?? current.firebaseServicePrivateKey,
        firebaseServicePrivateKeyId: imported.serviceAccount?.privateKeyId ?? current.firebaseServicePrivateKeyId,
        firebaseServiceClientId: imported.serviceAccount?.clientId ?? current.firebaseServiceClientId,
        firebaseServiceClientX509CertUrl: imported.serviceAccount?.clientX509CertUrl ?? current.firebaseServiceClientX509CertUrl,
      }));
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Firebase JSON importeren mislukt.');
    }
  }
}

function validateStep(stepIndex: number, form: SetupForm): string | null {
  if (stepIndex === 0) {
    if (!form.tenantName.trim()) return 'Tenantnaam is verplicht.';
    if (!form.publicUrl.trim()) return 'Publieke URL is verplicht.';
  }

  if (stepIndex === 1) {
    if (!form.adminName.trim()) return 'Naam van de eerste beheerder is verplicht.';
    if (!form.adminEmail.trim()) return 'E-mail van de eerste beheerder is verplicht.';
    if (form.adminPassword.length < 14) return 'Wachtwoord moet minimaal 14 tekens bevatten.';
    if (form.adminPassword !== form.adminPasswordConfirmation) return 'Wachtwoorden zijn niet gelijk.';
  }

  if (stepIndex === 4) {
    const hasAny = [
      form.firebaseServiceClientEmail,
      form.firebaseServicePrivateKey,
      form.firebaseServicePrivateKeyId,
      form.firebaseServiceClientId,
      form.firebaseServiceClientX509CertUrl,
    ].some((value) => value.trim() !== '');

    const hasRequired = form.firebaseServiceClientEmail.trim() !== '' && form.firebaseServicePrivateKey.trim() !== '';
    if (hasAny && !hasRequired) {
      return 'Vul minimaal client_email en private_key in, of laat het service account volledig leeg.';
    }
  }

  return null;
}

function validateAll(form: SetupForm): string | null {
  for (let index = 0; index < steps.length; index += 1) {
    const error = validateStep(index, form);
    if (error !== null) return error;
  }

  return null;
}

function normalizePublicUrl(value: string): string {
  const trimmed = value.trim().replace(/\/+$/, '');

  if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
    return trimmed;
  }

  return `https://${trimmed}`;
}
