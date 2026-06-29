import { FormEvent, useEffect, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { LockKeyhole } from 'lucide-react';
import { TotpQrCode } from '../../components/TotpQrCode';
import { ApiClientError } from '../../lib/apiClient';
import type { TwoFactorSetup } from '../../types/api';
import { useAuth } from './AuthContext';

interface LoginBranding {
  login_title: string;
  login_subtitle: string;
  logo_data_url: string;
}

export function LoginPage() {
  const { api, isAuthenticated, login, verifyTwoFactor, startTwoFactorSetup, enableTwoFactor } = useAuth();
  const navigate = useNavigate();
  const [branding, setBranding] = useState<LoginBranding>({
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
        <div className="login-panel__mark">
          {branding.logo_data_url ? <img src={branding.logo_data_url} alt="" /> : <LockKeyhole aria-hidden size={28} />}
        </div>
        <h1 id="login-title">{branding.login_title || 'D.I.S Command Center'}</h1>
        {branding.login_subtitle ? <p className="login-panel__subtitle">{branding.login_subtitle}</p> : null}

        {requiresTwoFactorSetup ? (
          <form onSubmit={confirmSetup} className="form">
            <TotpQrCode value={twoFactorSetup?.provisioning_uri} alt="MFA QR-code voor Authenticator app" helpText="Scan deze QR-code met je Authenticator app." />
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
              <input inputMode="numeric" pattern="[0-9]{6}" value={code} onChange={(event) => setCode(event.target.value)} required autoComplete="one-time-code" />
            </label>
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
                  <input type="email" value={email} onChange={(event) => setEmail(event.target.value)} required autoComplete="email" />
                </label>
                <label>
                  Wachtwoord
                  <input type="password" value={password} onChange={(event) => setPassword(event.target.value)} required autoComplete="current-password" />
                </label>
              </>
            ) : (
              <label>
                2FA-code
                <input inputMode="numeric" pattern="[0-9]{6}" value={code} onChange={(event) => setCode(event.target.value)} required autoComplete="one-time-code" />
              </label>
            )}
            {error && <p className="form-error">{error}</p>}
            <button className="primary-button" type="submit" disabled={busy}>
              {busy ? 'Verifieren...' : requiresTwoFactor ? 'Bevestigen' : 'Inloggen'}
            </button>
          </form>
        )}
      </section>
    </main>
  );
}
