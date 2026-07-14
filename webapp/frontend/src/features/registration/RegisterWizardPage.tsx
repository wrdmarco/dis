import { CheckCircle2, ChevronLeft, ChevronRight, KeyRound, ShieldCheck, Smartphone, UserRound } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { TotpQrCode } from '../../components/TotpQrCode';
import { ApiClientError } from '../../lib/apiClient';
import type { TwoFactorEnableResult, TwoFactorSetup, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface RegistrationInvite {
  user: User;
  requires_mfa: boolean;
  admin_app_allowed: boolean;
}

interface RegistrationCompleteResult extends RegistrationInvite {
  authenticated: boolean;
  requires_2fa?: boolean;
  two_factor_setup: TwoFactorSetup | null;
}

interface Step {
  key: 'account' | 'mfa' | 'install' | 'admin';
  title: string;
  icon: typeof UserRound;
}

export function RegisterWizardPage() {
  const router = useRouter();
  const { api, enableTwoFactor, verifyTwoFactor, refreshMe } = useAuth();
  const invitationRequestStarted = useRef(false);
  const [invite, setInvite] = useState<RegistrationInvite | null>(null);
  const [completed, setCompleted] = useState<RegistrationCompleteResult | null>(null);
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [twoFactorCode, setTwoFactorCode] = useState('');
  const [stepIndex, setStepIndex] = useState(0);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [recoveryMode, setRecoveryMode] = useState(false);

  const steps = useMemo<Step[]>(() => {
    const adminAllowed = completed?.admin_app_allowed ?? invite?.admin_app_allowed ?? false;
    const requiresMfa = completed === null
      ? invite?.requires_mfa ?? false
      : completed.requires_mfa && (completed.two_factor_setup !== null || completed.requires_2fa === true);
    return [
      { key: 'account', title: 'Account', icon: UserRound },
      ...(requiresMfa ? [{ key: 'mfa' as const, title: 'MFA', icon: ShieldCheck }] : []),
      { key: 'install', title: 'App installeren', icon: Smartphone },
      ...(adminAllowed ? [{ key: 'admin' as const, title: 'Admin app', icon: KeyRound }] : []),
    ];
  }, [
    completed?.admin_app_allowed,
    completed?.requires_2fa,
    completed?.requires_mfa,
    completed?.two_factor_setup,
    invite?.admin_app_allowed,
    invite?.requires_mfa,
  ]);

  const currentStep = steps[Math.min(stepIndex, steps.length - 1)];
  const setupUrl = typeof window === 'undefined' ? '' : window.location.origin;
  const canSubmitAccount = password.length > 0 && password === passwordConfirmation;
  const canSubmitMfa = /^\d{6}$/.test(twoFactorCode);
  const installStepIndex = Math.max(steps.findIndex((step) => step.key === 'install'), 0);
  const mfaPending = completed?.requires_mfa === true
    && (completed.two_factor_setup !== null || completed.requires_2fa === true);

  useEffect(() => {
    if (invitationRequestStarted.current) {
      return;
    }
    invitationRequestStarted.current = true;

    const fragment = new URLSearchParams(window.location.hash.replace(/^#/, ''));
    const query = new URLSearchParams(window.location.search);
    const email = fragment.get('email') ?? '';
    const token = fragment.get('token') ?? '';
    const isRecovery = fragment.get('mode') === 'recovery';
    setRecoveryMode(isRecovery);

    if (window.location.hash !== '' || query.has('email') || query.has('token')) {
      window.history.replaceState(window.history.state, '', window.location.pathname);
    }

    async function loadInvite() {
      setLoading(true);
      setError(null);
      try {
        const response = await api.post<RegistrationInvite>(
          '/registration/invite',
          email && token ? { email, token } : {},
        );
        setInvite(response.data);
      } catch (err) {
        setError(err instanceof Error ? err.message : isRecovery ? 'Herstellink kon niet worden geladen.' : 'Registratielink kon niet worden geladen.');
      } finally {
        setLoading(false);
      }
    }

    void loadInvite();
  }, [api]);

  async function completeAccount(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canSubmitAccount) {
      setError('Controleer het wachtwoord en de bevestiging.');
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const response = await api.post<RegistrationCompleteResult>('/registration/complete', {
        password,
        password_confirmation: passwordConfirmation,
      });

      setCompleted(response.data);
      if (response.data.authenticated) {
        await refreshMe();
      }
      setStepIndex(1);
    } catch (err) {
        setError(err instanceof Error ? err.message : recoveryMode ? 'Wachtwoord herstellen mislukt.' : 'Registratie afronden mislukt.');
    } finally {
      setSaving(false);
    }
  }

  async function completeMfa(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canSubmitMfa) {
      setError('Vul een geldige 6-cijferige MFA-code in.');
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const result: TwoFactorEnableResult | User = completed?.two_factor_setup === null
        ? await verifyTwoFactor(twoFactorCode)
        : await enableTwoFactor(twoFactorCode);
      const nextUser = 'user' in result ? result.user : result;
      const authenticated = 'authenticated' in result
        ? result.authenticated
        : completed?.admin_app_allowed === true;
      setCompleted((current) => current === null ? current : {
        ...current,
        authenticated,
        requires_2fa: false,
        user: nextUser,
        two_factor_setup: null,
      });
      setStepIndex(1);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'MFA activeren mislukt.');
    } finally {
      setSaving(false);
    }
  }

  function nextStep() {
    const next = Math.min(stepIndex + 1, steps.length - 1);
    setError(null);
    setStepIndex(next);
  }

  function previousStep() {
    const minimumStep = completed === null ? 0 : mfaPending ? steps.findIndex((step) => step.key === 'mfa') : installStepIndex;
    const previous = Math.max(stepIndex - 1, minimumStep);
    setError(null);
    setStepIndex(previous);
  }

  function finish() {
    if (completed?.authenticated) {
      void refreshMe().catch(() => null);
      router.push('/');
      return;
    }

    router.push('/login');
  }

  function canOpenStep(step: Step): boolean {
    if (completed === null) {
      return step.key === 'account';
    }

    if (mfaPending) {
      return step.key === 'mfa';
    }

    return step.key === 'install' || step.key === 'admin';
  }

  return (
    <main className="setup-shell">
      <section className="setup-panel" aria-labelledby="registration-title">
        <div className="setup-panel__header">
          <div className="public-download-panel__mark">
            {completed ? <CheckCircle2 aria-hidden size={30} /> : <UserRound aria-hidden size={30} />}
          </div>
          <div>
            <span className="topbar__eyebrow">{recoveryMode ? 'D.I.S accountbeveiliging' : 'D.I.S registratie'}</span>
            <h1 id="registration-title">{recoveryMode ? 'Wachtwoord herstellen' : 'Welkom bij Nationaal Droneteam'}</h1>
          </div>
        </div>

        {loading ? <p className="public-download-panel__status setup-status-line">Registratiegegevens laden...</p> : null}
        {error ? <p className="form-error setup-status-line">{error}</p> : null}

        {!loading && invite ? (
          <>
            <nav className="setup-stepper" aria-label="Registratiestappen">
              {steps.map((step, index) => {
                const Icon = step.icon;
                return (
                  <button
                    key={step.key}
                    type="button"
                    className={index === stepIndex ? 'setup-stepper__item setup-stepper__item--active' : 'setup-stepper__item'}
                    onClick={() => {
                      if (canOpenStep(step)) {
                        setStepIndex(index);
                      }
                    }}
                    disabled={!canOpenStep(step)}
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

            {currentStep.key !== 'account' && currentStep.key !== 'mfa' ? (
              <div className="setup-actions">
                <button className="secondary-button" type="button" onClick={previousStep} disabled={stepIndex <= installStepIndex}>
                  <ChevronLeft size={16} /> Vorige
                </button>
                {stepIndex < steps.length - 1 ? (
                  <button className="primary-button" type="button" onClick={nextStep}>
                    Volgende <ChevronRight size={16} />
                  </button>
                ) : (
                  <button className="primary-button" type="button" onClick={finish}>
                    Naar D.I.S
                  </button>
                )}
              </div>
            ) : null}
          </>
        ) : null}

        {!loading && !invite ? (
          <div className="setup-complete">
            <ShieldCheck aria-hidden size={22} />
            <div>
              <strong>Registratie niet beschikbaar.</strong>
              <p>Vraag een beheerder om een nieuwe uitnodiging.</p>
            </div>
            <Link className="secondary-button" href="/login">Naar login</Link>
          </div>
        ) : null}
      </section>
    </main>
  );

  function renderStep() {
    if (currentStep.key === 'account') {
      return (
        <form className="form-grid" onSubmit={completeAccount}>
          <div className="setup-review form-grid__wide">
            <div><span>Naam</span><strong>{invite?.user.name}</strong></div>
            <div><span>E-mail</span><strong>{invite?.user.email}</strong></div>
          </div>
          <label>
            Wachtwoord
            <input type="password" value={password} required autoComplete="new-password" onChange={(event) => setPassword(event.target.value)} />
          </label>
          <label>
            Wachtwoord bevestigen
            <input type="password" value={passwordConfirmation} required autoComplete="new-password" onChange={(event) => setPasswordConfirmation(event.target.value)} />
          </label>
          <p className="form-hint form-grid__wide">Het wachtwoord moet voldoen aan het ingestelde wachtwoordbeleid.</p>
          <div className="setup-actions form-grid__wide">
            <button className="primary-button" type="submit" disabled={saving || !canSubmitAccount}>
              {saving ? 'Opslaan...' : recoveryMode ? 'Nieuw wachtwoord opslaan' : 'Account activeren'} <ChevronRight size={16} />
            </button>
          </div>
        </form>
      );
    }

    if (currentStep.key === 'mfa') {
      return (
        <form className="form-grid" onSubmit={completeMfa}>
          <div className="tenant-qr form-grid__wide">
            <TotpQrCode value={completed?.two_factor_setup?.provisioning_uri} alt="MFA QR-code voor Authenticator app" helpText="Scan deze QR-code met je Authenticator app." />
            <code>{completed?.two_factor_setup?.secret}</code>
          </div>
          <label className="form-grid__wide">
            MFA-code
            <input inputMode="numeric" pattern="[0-9]*" maxLength={6} value={twoFactorCode} onChange={(event) => setTwoFactorCode(event.target.value.replace(/\D/g, '').slice(0, 6))} required />
          </label>
          <div className="setup-actions form-grid__wide">
            <button className="primary-button" type="submit" disabled={saving || !canSubmitMfa}>
              {saving ? 'Controleren...' : 'MFA activeren'} <ChevronRight size={16} />
            </button>
          </div>
        </form>
      );
    }

    if (currentStep.key === 'install') {
      return (
        <>
          <div className="setup-copy">
            <strong>Installeer de mobiele app via Google Play of de App Store.</strong>
            <p>Gebruik daarna de server-QR om de app aan deze D.I.S omgeving te koppelen.</p>
          </div>
          <div className="tenant-qr">
            <TotpQrCode value={setupUrl} alt="QR-code met DIS server URL" helpText="Scan deze QR-code in de Android app." />
            <code>{setupUrl}</code>
          </div>
        </>
      );
    }

    return (
      <>
        <div className="setup-copy">
          <strong>Admin app beschikbaar.</strong>
          <p>Je account heeft beheerrechten. Installeer naast de operator app ook de admin app via de appstore zodra deze daar beschikbaar is.</p>
        </div>
        <div className="setup-review">
          <div><span>Account</span><strong>{completed?.user.email ?? invite?.user.email}</strong></div>
          <div><span>Toegang</span><strong>Admin app toegestaan</strong></div>
        </div>
      </>
    );
  }
}
