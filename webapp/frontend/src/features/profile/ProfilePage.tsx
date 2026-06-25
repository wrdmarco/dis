import { FormEvent, useEffect, useMemo, useState } from 'react';
import { KeyRound, ShieldCheck } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { TotpQrCode } from '../../components/TotpQrCode';
import { ApiClientError } from '../../lib/apiClient';
import { useAuth } from '../auth/AuthContext';
import type { TwoFactorSetup } from '../../types/api';

export function ProfilePage() {
  const { user, startTwoFactorSetup, enableTwoFactor, disableTwoFactor } = useAuth();
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null);
  const [enableCode, setEnableCode] = useState('');
  const [disablePassword, setDisablePassword] = useState('');
  const [disableCode, setDisableCode] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [autoSetupStarted, setAutoSetupStarted] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const mfaRequiredByRole = useMemo(
    () => user?.roles?.some((role) => role.requires_two_factor) ?? false,
    [user?.roles],
  );

  useEffect(() => {
    if (!user || user.two_factor_enabled || !mfaRequiredByRole || setup !== null || autoSetupStarted) {
      return;
    }

    setAutoSetupStarted(true);
    void startSetup();
  }, [autoSetupStarted, mfaRequiredByRole, setup, user]);

  async function startSetup() {
    setBusy(true);
    setError(null);
    setMessage(null);
    setRecoveryCodes([]);
    try {
      setSetup(await startTwoFactorSetup());
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'MFA setup starten mislukt.');
    } finally {
      setBusy(false);
    }
  }

  async function confirmSetup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      const result = await enableTwoFactor(enableCode);
      setRecoveryCodes(result.recovery_codes);
      setSetup(null);
      setEnableCode('');
      setMessage('MFA is ingeschakeld.');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'MFA inschakelen mislukt.');
    } finally {
      setBusy(false);
    }
  }

  async function disable(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError(null);
    setMessage(null);
    try {
      await disableTwoFactor(disablePassword, disableCode);
      setDisablePassword('');
      setDisableCode('');
      setMessage('MFA is uitgeschakeld.');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'MFA uitzetten mislukt.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="page-stack">
      <Panel title="Profiel">
        <div className="definition-grid">
          <dt>Naam</dt>
          <dd>{user?.name ?? '-'}</dd>
          <dt>E-mail</dt>
          <dd>{user?.email ?? '-'}</dd>
          <dt>Rollen</dt>
          <dd>{user?.roles?.map((role) => role.display_name).join(', ') || '-'}</dd>
          <dt>MFA status</dt>
          <dd>{user?.two_factor_enabled ? 'Ingeschakeld' : 'Uitgeschakeld'}</dd>
          <dt>MFA verplicht</dt>
          <dd>{mfaRequiredByRole ? 'Ja, door rol' : 'Nee'}</dd>
        </div>
      </Panel>

      <Panel title="Multi-factor authenticatie">
        <div className="mfa-card">
          <div className="mfa-card__icon"><ShieldCheck size={22} /></div>
          <div>
            <strong>{user?.two_factor_enabled ? 'MFA actief' : 'MFA niet actief'}</strong>
            <p>{mfaRequiredByRole && !user?.two_factor_enabled ? 'Stel je Authenticator app in om verder te gaan.' : 'Gebruik een authenticator app met 6-cijferige TOTP-codes.'}</p>
          </div>
          {!user?.two_factor_enabled ? (
            <button className="primary-button" type="button" onClick={startSetup} disabled={busy}>
              <KeyRound size={16} /> MFA instellen
            </button>
          ) : null}
        </div>

        {setup?.secret ? (
          <form className="form-grid" onSubmit={confirmSetup}>
            <div className="form-grid__wide">
              <TotpQrCode value={setup.provisioning_uri} />
            </div>
            <label className="form-grid__wide">
              Secret
              <input className="mono" value={setup.secret} readOnly />
            </label>
            <label className="form-grid__wide">
              Authenticator URI
              <textarea className="mono" value={setup.provisioning_uri ?? ''} readOnly />
            </label>
            <label>
              6-cijferige code
              <input inputMode="numeric" pattern="[0-9]{6}" value={enableCode} onChange={(event) => setEnableCode(event.target.value)} required />
            </label>
            <div className="actions-row form-grid__wide">
              <button className="primary-button" type="submit" disabled={busy || enableCode.length !== 6}>
                MFA bevestigen
              </button>
            </div>
          </form>
        ) : null}

        {user?.two_factor_enabled ? (
          <form className="form-grid" onSubmit={disable}>
            <label>
              Huidig wachtwoord
              <input type="password" value={disablePassword} onChange={(event) => setDisablePassword(event.target.value)} required />
            </label>
            <label>
              6-cijferige MFA-code
              <input inputMode="numeric" pattern="[0-9]{6}" value={disableCode} onChange={(event) => setDisableCode(event.target.value)} required />
            </label>
            <div className="actions-row form-grid__wide">
              <button className="secondary-button" type="submit" disabled={busy || mfaRequiredByRole}>
                MFA uitzetten
              </button>
            </div>
          </form>
        ) : null}

        {mfaRequiredByRole && user?.two_factor_enabled ? (
          <p className="error-text">MFA kan pas uit nadat alle rollen die MFA verplichten zijn aangepast.</p>
        ) : null}
        {error ? <p className="error-text">{error}</p> : null}
        {message ? <p className="success-text">{message}</p> : null}
      </Panel>

      {recoveryCodes.length > 0 ? (
        <Panel title="Recovery codes">
          <pre>{recoveryCodes.join('\n')}</pre>
        </Panel>
      ) : null}
    </div>
  );
}
