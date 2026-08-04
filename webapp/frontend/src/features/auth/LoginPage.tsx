import { FormEvent, useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { CheckCircle2, Clock3, KeyRound, LockKeyhole, Mail, RefreshCw, ShieldCheck, Smartphone } from 'lucide-react';
import { TotpQrCode } from '../../components/TotpQrCode';
import { ApiClientError } from '../../lib/apiClient';
import type { TwoFactorSetup, User, WebLoginApprovalState } from '../../types/api';
import { useAuth } from './AuthContext';

interface LoginBranding {
  name: string;
  short_name: string;
  tenant_name: string;
  login_title: string;
  login_subtitle: string;
  logo_data_url: string;
}

export function LoginPage() {
  const {
    api,
    isAuthenticated,
    user,
    login,
    verifyTwoFactor,
    getMobileApprovalStatus,
    completeMobileApproval,
    resendMobileApproval,
    refreshMe,
    startTwoFactorSetup,
    enableTwoFactor,
  } = useAuth();
  const router = useRouter();
  const [branding, setBranding] = useState<LoginBranding>({
    name: 'DIS',
    short_name: 'DIS',
    tenant_name: 'Nationaal Droneteam',
    login_title: '',
    login_subtitle: '',
    logo_data_url: '',
  });
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [requiresTwoFactor, setRequiresTwoFactor] = useState(false);
  const [requiresTwoFactorSetup, setRequiresTwoFactorSetup] = useState(false);
  const [twoFactorSetup, setTwoFactorSetup] = useState<TwoFactorSetup | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [mobileApproval, setMobileApproval] = useState<WebLoginApprovalState | null>(null);
  const [mobileApprovalNotice, setMobileApprovalNotice] = useState<string | null>(null);
  const [mobileApprovalBusy, setMobileApprovalBusy] = useState(false);
  const [nowMs, setNowMs] = useState(() => Date.now());
  const completionInFlight = useRef(false);

  useEffect(() => {
    api.get<LoginBranding>('/branding')
      .then((response) => setBranding(response.data))
      .catch(() => undefined);
  }, [api]);

  useEffect(() => {
    document.title = loginDocumentTitle(branding);
  }, [branding]);

  useEffect(() => {
    if (isAuthenticated && !requiresTwoFactor && !requiresTwoFactorSetup) {
      router.replace(loginLandingPath(user));
    }
  }, [isAuthenticated, requiresTwoFactor, requiresTwoFactorSetup, router, user]);

  useEffect(() => {
    if (!requiresTwoFactor || mobileApproval?.expires_at === null || mobileApproval?.expires_at === undefined) {
      return;
    }

    setNowMs(Date.now());
    const interval = window.setInterval(() => setNowMs(Date.now()), 1_000);

    return () => window.clearInterval(interval);
  }, [mobileApproval?.expires_at, requiresTwoFactor]);

  useEffect(() => {
    if (!requiresTwoFactor
      || mobileApproval?.available !== true
      || !['pending', 'approved'].includes(mobileApproval.status)) {
      return;
    }

    let disposed = false;
    let timer: number | null = null;
    const recoverCompletedSession = async (): Promise<boolean> => {
      try {
        const authenticatedUser = await refreshMe();
        if (disposed || authenticatedUser === null) return false;
        setRequiresTwoFactor(false);
        router.replace(loginLandingPath(authenticatedUser));
        return true;
      } catch {
        return false;
      }
    };
    const schedule = (seconds: number) => {
      if (disposed) return;
      timer = window.setTimeout(() => void poll(), Math.max(1, seconds) * 1_000);
    };
    const poll = async () => {
      try {
        const status = await getMobileApprovalStatus();
        if (disposed) return;
        if (status.status === 'approved' && mobileApproval.status !== 'approved') {
          setMobileApproval(status);
          setMobileApprovalNotice(null);
          return;
        }
        setMobileApproval(status);
        setMobileApprovalNotice(null);

        if (status.status === 'approved') {
          if (completionInFlight.current) return;
          completionInFlight.current = true;
          setMobileApprovalBusy(true);
          try {
            const approvedUser = await completeMobileApproval();
            if (!disposed) {
              setRequiresTwoFactor(false);
              router.replace(loginLandingPath(approvedUser));
            }
          } catch (err) {
            if (disposed) return;
            if (await recoverCompletedSession()) return;
            completionInFlight.current = false;
            setMobileApprovalBusy(false);
            if (err instanceof ApiClientError && err.code === 'login_approval_expired') {
              setMobileApproval((current) => current === null ? null : { ...current, status: 'expired' });
            } else {
              setMobileApprovalNotice('Goedkeuring ontvangen, maar afronden lukte nog niet. We proberen het opnieuw.');
              schedule(status.poll_after_seconds);
            }
          }
          return;
        }

        if (status.status === 'denied') {
          setCode('');
          setRequiresTwoFactor(false);
          setMobileApproval(null);
          setError('Het inlogverzoek is in de app geweigerd. Log opnieuw in om het nogmaals te proberen.');
          return;
        }

        if (status.status === 'pending') {
          schedule(status.poll_after_seconds);
        }
      } catch (err) {
        if (disposed) return;
        if (err instanceof ApiClientError && [401, 403].includes(err.status)) {
          if (await recoverCompletedSession()) return;
          setCode('');
          setRequiresTwoFactor(false);
          setMobileApproval(null);
          setError('De MFA-sessie is verlopen. Log opnieuw in met je e-mailadres en wachtwoord.');
          return;
        }
        if (err instanceof ApiClientError && err.code === 'login_approval_denied') {
          setCode('');
          setRequiresTwoFactor(false);
          setMobileApproval(null);
          setError('Het inlogverzoek is in de app geweigerd. Log opnieuw in om het nogmaals te proberen.');
          return;
        }
        setMobileApprovalNotice('De appstatus kan tijdelijk niet worden opgehaald. De MFA-code blijft werken.');
        schedule(mobileApproval.poll_after_seconds);
      }
    };

    schedule(mobileApproval.status === 'approved' ? 1 : mobileApproval.poll_after_seconds);

    return () => {
      disposed = true;
      if (timer !== null) window.clearTimeout(timer);
    };
  }, [
    completeMobileApproval,
    getMobileApprovalStatus,
    mobileApproval?.available,
    mobileApproval?.poll_after_seconds,
    mobileApproval?.status,
    refreshMe,
    requiresTwoFactor,
    router,
  ]);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      if (requiresTwoFactor) {
        const verifiedUser = await verifyTwoFactor(code);
        router.replace(loginLandingPath(verifiedUser));
        return;
      }

      const result = await login(email, password);
      if (result.requires_2fa_setup) {
        setRequiresTwoFactorSetup(true);
        setCode('');
        setTwoFactorSetup(result.two_factor_setup ?? await startTwoFactorSetup());
        setMobileApproval(null);
        return;
      }

      if (result.requires_2fa) {
        setRequiresTwoFactor(true);
        setMobileApproval(result.mobile_approval ?? null);
        setMobileApprovalNotice(null);
        completionInFlight.current = false;
      } else {
        router.replace(loginLandingPath(result.user));
      }
    } catch (err) {
      if (err instanceof ApiClientError && err.code === 'invalid_two_factor_code') {
        setCode('');
        setError('De MFA-code is niet juist. Controleer de actuele code en probeer het opnieuw.');
      } else if (err instanceof ApiClientError && err.code === 'login_approval_denied') {
        setCode('');
        setRequiresTwoFactor(false);
        setMobileApproval(null);
        setError('Het inlogverzoek is in de app geweigerd. Log opnieuw in om het nogmaals te proberen.');
      } else if (err instanceof ApiClientError && (err.code === 'two_factor_challenge_locked' || err.status === 401)) {
        setCode('');
        setRequiresTwoFactor(false);
        setRequiresTwoFactorSetup(false);
        setTwoFactorSetup(null);
        setMobileApproval(null);
        setError('De MFA-sessie is verlopen. Log opnieuw in met je e-mailadres en wachtwoord.');
      } else {
        setError(err instanceof ApiClientError ? err.message : 'Inloggen mislukt.');
      }
    } finally {
      setBusy(false);
    }
  };

  const resendApproval = async () => {
    setMobileApprovalBusy(true);
    setMobileApprovalNotice(null);
    try {
      const approval = await resendMobileApproval();
      setMobileApproval(approval);
      completionInFlight.current = false;
    } catch (err) {
      setMobileApprovalNotice(err instanceof ApiClientError && err.status === 429
        ? 'Wacht even voordat je opnieuw een melding stuurt.'
        : 'De melding kon niet opnieuw worden verstuurd. De MFA-code blijft werken.');
    } finally {
      setMobileApprovalBusy(false);
    }
  };

  const approvalSecondsRemaining = mobileApproval?.expires_at === null || mobileApproval?.expires_at === undefined
    ? null
    : Math.max(0, Math.ceil((Date.parse(mobileApproval.expires_at) - nowMs) / 1_000));

  const confirmSetup = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const result = await enableTwoFactor(code);
      setRecoveryCodes(result.recovery_codes);
      setRequiresTwoFactorSetup(false);
      router.replace(loginLandingPath(result.user));
    } catch (err) {
      if (err instanceof ApiClientError && err.code === 'invalid_two_factor_code') {
        setCode('');
        setError('De MFA-code is niet juist. Wacht op een actuele code en probeer het opnieuw.');
      } else if (err instanceof ApiClientError && err.status === 401) {
        setCode('');
        setRequiresTwoFactorSetup(false);
        setTwoFactorSetup(null);
        setError('De MFA-sessie is verlopen. Log opnieuw in om MFA in te stellen.');
      } else {
        setError(err instanceof ApiClientError ? err.message : 'MFA inschakelen mislukt.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <main className="login-shell">
      <section className="login-panel" aria-labelledby="login-title">
        <div className="login-panel__brand">
          <div className="login-panel__mark">
            {branding.logo_data_url ? <img src={branding.logo_data_url} alt="" /> : <LockKeyhole aria-hidden size={30} />}
          </div>
          <div>
            <span>{branding.tenant_name || 'Nationaal Droneteam'}</span>
            <h1 id="login-title">{branding.login_title || branding.name || 'Command Center'}</h1>
          </div>
        </div>
        <p className="login-panel__subtitle">
          {branding.login_subtitle || (requiresTwoFactor || requiresTwoFactorSetup ? 'Bevestig je identiteit om verder te gaan.' : 'Log in op het operationeel beeld.')}
        </p>

        {requiresTwoFactorSetup ? (
          <form onSubmit={confirmSetup} className="form">
            <div className="login-state">
              <ShieldCheck size={18} />
              <strong>MFA activeren</strong>
            </div>
            <div className="login-mfa-grid">
              <TotpQrCode value={twoFactorSetup?.provisioning_uri} alt="MFA QR-code voor Authenticator app" helpText="Scan deze QR-code met je Authenticator app." />
              <div className="login-mfa-fields">
                <label>
                  Authenticator secret
                  <input className="mono" value={twoFactorSetup?.secret ?? ''} readOnly />
                </label>
                <label>
                  Authenticator URI
                  <textarea className="mono" value={twoFactorSetup?.provisioning_uri ?? ''} readOnly />
                </label>
                <label>
                  6-cijferige code
                  <div className="input-with-icon">
                    <KeyRound size={17} />
                    <input inputMode="numeric" pattern="[0-9]{6}" value={code} onChange={(event) => setCode(event.target.value)} required autoComplete="one-time-code" />
                  </div>
                </label>
              </div>
            </div>
            {error && <p className="form-error">{error}</p>}
            <button className="primary-button" type="submit" disabled={busy || code.length !== 6}>
              {busy ? 'Bevestigen...' : 'MFA activeren'}
            </button>
            {recoveryCodes.length > 0 ? <pre>{recoveryCodes.join('\n')}</pre> : null}
          </form>
        ) : (
          <form onSubmit={submit} className="form">
            {!requiresTwoFactor ? (
              <>
                <label>
                  E-mail
                  <div className="input-with-icon">
                    <Mail size={17} />
                    <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required autoComplete="email" />
                  </div>
                </label>
                <label>
                  Wachtwoord
                  <div className="input-with-icon">
                    <LockKeyhole size={17} />
                    <input type="password" value={password} onChange={(event) => setPassword(event.target.value)} required autoComplete="current-password" />
                  </div>
                </label>
              </>
            ) : (
              <>
                {mobileApproval?.available ? (
                  <div className={`login-mobile-approval login-mobile-approval--${mobileApproval.status}`} aria-live="polite">
                    <div className="login-mobile-approval__heading">
                      {['approved', 'consumed'].includes(mobileApproval.status)
                        ? <CheckCircle2 aria-hidden size={20} />
                        : <Smartphone aria-hidden size={20} />}
                      <strong>{mobileApprovalHeading(mobileApproval.status)}</strong>
                    </div>
                    {mobileApproval.status === 'pending' ? (
                      <>
                        <p>Open de Operator-app en controleer of dit verificatienummer overeenkomt.</p>
                        <span className="login-mobile-approval__number">{mobileApproval.verification_number}</span>
                        <div className="login-mobile-approval__meta">
                          <Clock3 aria-hidden size={15} />
                          <span>{approvalSecondsRemaining === null ? 'Even geduld…' : `Nog ${formatCountdown(approvalSecondsRemaining)}`}</span>
                        </div>
                      </>
                    ) : (
                      <p>{mobileApprovalDescription(mobileApproval.status)}</p>
                    )}
                    {['pending', 'expired'].includes(mobileApproval.status) ? (
                      <button className="secondary-button login-mobile-approval__resend" type="button" onClick={() => void resendApproval()} disabled={mobileApprovalBusy}>
                        <RefreshCw aria-hidden size={15} />
                        {mobileApprovalBusy ? 'Versturen…' : 'Opnieuw sturen'}
                      </button>
                    ) : null}
                    {mobileApprovalNotice ? <small>{mobileApprovalNotice}</small> : null}
                  </div>
                ) : mobileApproval?.status === 'unavailable' ? (
                  <p className="login-mobile-approval__fallback">Geen geschikte Operator-app bereikbaar. Gebruik je authenticator- of herstelcode.</p>
                ) : null}
                <label>
                  Authenticator- of herstelcode
                  <div className="input-with-icon">
                    <KeyRound size={17} />
                    <input value={code} maxLength={32} onChange={(event) => setCode(event.target.value)} required autoComplete="one-time-code" />
                  </div>
                </label>
              </>
            )}
            {error && <p className="form-error">{error}</p>}
            <button className="primary-button" type="submit" disabled={busy}>
              {busy ? 'Verifieren...' : requiresTwoFactor ? 'Bevestigen' : 'Inloggen'}
            </button>
          </form>
        )}
        <div className="login-panel__footer">
          <ShieldCheck size={15} />
          <span>Beveiligde toegang</span>
        </div>
      </section>
    </main>
  );
}

function formatCountdown(seconds: number): string {
  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = seconds % 60;
  return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
}

function mobileApprovalHeading(status: WebLoginApprovalState['status']): string {
  switch (status) {
    case 'pending': return 'Goedkeuren via de app';
    case 'approved': return 'Goedgekeurd in de app';
    case 'denied': return 'Verzoek geweigerd';
    case 'expired': return 'Verzoek verlopen';
    case 'cancelled': return 'Verzoek geannuleerd';
    case 'consumed': return 'Inloggen afgerond';
    case 'unavailable': return 'App-goedkeuring niet beschikbaar';
  }
}

function mobileApprovalDescription(status: WebLoginApprovalState['status']): string {
  switch (status) {
    case 'approved': return 'De beveiligde browsersessie wordt afgerond…';
    case 'denied': return 'Dit inlogverzoek is in de app geweigerd. Log opnieuw in om het nogmaals te proberen.';
    case 'expired': return 'De melding is verlopen. Stuur een nieuwe melding of gebruik je MFA-code.';
    case 'cancelled': return 'Dit inlogverzoek is geannuleerd. Gebruik je MFA-code of log opnieuw in.';
    case 'consumed': return 'Dit inlogverzoek is al veilig afgerond.';
    case 'pending': return 'Open de Operator-app om dit verzoek te beoordelen.';
    case 'unavailable': return 'Gebruik je authenticator- of herstelcode.';
  }
}

function loginDocumentTitle(branding: LoginBranding): string {
  const title = firstNonEmpty(branding.login_title, branding.name, branding.short_name, branding.tenant_name) ?? 'DIS';
  const tenantName = firstNonEmpty(branding.tenant_name);
  return tenantName !== null && !title.toLocaleLowerCase('nl-NL').includes(tenantName.toLocaleLowerCase('nl-NL'))
    ? `${title} | ${tenantName}`
    : title;
}

function firstNonEmpty(...values: string[]): string | null {
  for (const value of values) {
    const trimmed = value.trim();
    if (trimmed !== '') {
      return trimmed;
    }
  }

  return null;
}

function loginLandingPath(user?: User | null): '/' | '/profile' {
  return user?.roles?.some((role) => role.can_use_admin_app) === true ? '/' : '/profile';
}
