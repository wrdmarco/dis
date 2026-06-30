import { FormEvent, useEffect, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { KeyRound, LockKeyhole, Mail, ShieldCheck } from 'lucide-react';
import { TotpQrCode } from '../../components/TotpQrCode';
import { ApiClientError } from '../../lib/apiClient';
import type { TwoFactorSetup } from '../../types/api';
import { useAuth } from './AuthContext';

interface LoginBranding {
  tenant_name: string;
  login_title: string;
  login_subtitle: string;
  logo_data_url: string;
}

export function LoginPage() {
  const { api, isAuthenticated, login, verifyTwoFactor, startTwoFactorSetup, enableTwoFactor } = useAuth();
  const navigate = useNavigate();
  const [branding, setBranding] = useState<LoginBranding>({
    tenant_name: 'Nationaal Droneteam',
    login_title: 'D.I.S Command Center',
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

  useEffect(() => {
    api.get<LoginBranding>('/branding')
      .then((response) => setBranding(response.data))
      .catch(() => undefined);
  }, [api]);

  if (isAuthenticated && !requiresTwoFactor && !requiresTwoFactorSetup) {
    return <Navigate to="/" replace />;
  }

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      if (requiresTwoFactor) {
        await verifyTwoFactor(code);
        navigate('/', { replace: true });
        return;
      }

      const result = await login(email, password);
      if (result.requires_2fa_setup) {
        setRequiresTwoFactorSetup(true);
        setCode('');
        setTwoFactorSetup(result.two_factor_setup ?? await startTwoFactorSetup());
        return;
      }

      if (result.requires_2fa) {
        setRequiresTwoFactor(true);
      } else {
        navigate('/', { replace: true });
      }
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Inloggen mislukt.');
    } finally {
      setBusy(false);
    }
  };

  const confirmSetup = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const result = await enableTwoFactor(code);
      setRecoveryCodes(result.recovery_codes);
      setRequiresTwoFactorSetup(false);
      navigate('/', { replace: true });
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'MFA inschakelen mislukt.');
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
            <h1 id="login-title">{branding.login_title || 'D.I.S Command Center'}</h1>
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
              <label>
                2FA-code
                <div className="input-with-icon">
                  <KeyRound size={17} />
                  <input inputMode="numeric" pattern="[0-9]{6}" value={code} onChange={(event) => setCode(event.target.value)} required autoComplete="one-time-code" />
                </div>
              </label>
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
