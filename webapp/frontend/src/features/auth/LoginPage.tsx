import { FormEvent, useState } from 'react';
import { Link, Navigate, useNavigate } from 'react-router-dom';
import { LockKeyhole } from 'lucide-react';
import { ApiClientError } from '../../lib/apiClient';
import { useAuth } from './AuthContext';

export function LoginPage() {
  const { isAuthenticated, login, verifyTwoFactor } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [requiresTwoFactor, setRequiresTwoFactor] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (isAuthenticated && !requiresTwoFactor) {
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

  return (
    <main className="login-shell">
      <section className="login-panel" aria-labelledby="login-title">
        <div className="login-panel__mark">
          <LockKeyhole aria-hidden size={28} />
        </div>
        <h1 id="login-title">D.I.S Command Center</h1>
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
            {busy ? 'Verifiëren' : requiresTwoFactor ? 'Bevestigen' : 'Inloggen'}
          </button>
        </form>
        <Link className="public-link" to="/download">Android APK downloaden</Link>
      </section>
    </main>
  );
}
