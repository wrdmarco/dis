import { FormEvent, useEffect, useMemo, useState } from 'react';
import { KeyRound, ShieldCheck, Trash2 } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { TotpQrCode } from '../../components/TotpQrCode';
import { ApiClientError } from '../../lib/apiClient';
import { droneTypeLabel } from '../../lib/droneTypes';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Asset, Certification, DroneType, TwoFactorSetup, UserCertification, UserVacation } from '../../types/api';

type OwnAssetStatus = 'ready' | 'maintenance' | 'unavailable';

function emptyAssetForm() {
  return {
    id: null as string | null,
    name: '',
    type: 'drone',
    droneTypeId: '',
    hasSpotlight: false,
    hasSpeaker: false,
    status: 'ready' as OwnAssetStatus,
    serialNumber: '',
    maintenanceDueAt: '',
    notes: '',
  };
}

function emptyCertificationForm() {
  return {
    id: null as string | null,
    certificationId: '',
    issuedAt: todayInputValue(),
    expiresAt: '',
    certificateNumber: '',
    status: 'active' as UserCertification['status'],
  };
}

export function ProfilePage() {
  const { api, user, startTwoFactorSetup, enableTwoFactor, disableTwoFactor } = useAuth();
  const vacations = useApiResource<UserVacation[]>('/vacations/mine');
  const assets = useApiResource<Asset[]>('/assets/mine');
  const droneTypes = useApiResource<DroneType[]>('/drone-types');
  const certificationOptions = useApiResource<Certification[]>('/certifications/options');
  const userCertifications = useApiResource<UserCertification[]>('/certifications/me');
  const [setup, setSetup] = useState<TwoFactorSetup | null>(null);
  const [enableCode, setEnableCode] = useState('');
  const [disablePassword, setDisablePassword] = useState('');
  const [disableCode, setDisableCode] = useState('');
  const [vacationForm, setVacationForm] = useState({ startsAt: todayInputValue(), endsAt: todayInputValue(), note: '' });
  const [assetForm, setAssetForm] = useState(emptyAssetForm());
  const [certificationForm, setCertificationForm] = useState(emptyCertificationForm());
  const [savingVacation, setSavingVacation] = useState(false);
  const [savingAsset, setSavingAsset] = useState(false);
  const [savingCertification, setSavingCertification] = useState(false);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [autoSetupStarted, setAutoSetupStarted] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [vacationMessage, setVacationMessage] = useState<string | null>(null);
  const [vacationError, setVacationError] = useState<string | null>(null);
  const [assetMessage, setAssetMessage] = useState<string | null>(null);
  const [assetError, setAssetError] = useState<string | null>(null);
  const [certificationMessage, setCertificationMessage] = useState<string | null>(null);
  const [certificationError, setCertificationError] = useState<string | null>(null);
  const selectedAssetDroneType = droneTypes.data?.find((type) => type.id === assetForm.droneTypeId) ?? null;
  const assetSupportsSpotlight = assetForm.type === 'drone' && selectedAssetDroneType?.has_spotlight === true;
  const assetSupportsSpeaker = assetForm.type === 'drone' && selectedAssetDroneType?.has_speaker === true;

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

  async function submitVacation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavingVacation(true);
    setVacationError(null);
    setVacationMessage(null);
    try {
      await api.post<UserVacation>('/vacations/mine', {
        starts_at: vacationForm.startsAt,
        ends_at: vacationForm.endsAt,
        note: vacationForm.note.trim() === '' ? null : vacationForm.note.trim(),
      });
      setVacationForm({ startsAt: todayInputValue(), endsAt: todayInputValue(), note: '' });
      setVacationMessage('Vakantie opgeslagen.');
      await vacations.reload();
    } catch (err) {
      setVacationError(err instanceof ApiClientError ? err.message : 'Vakantie kon niet worden opgeslagen.');
    } finally {
      setSavingVacation(false);
    }
  }

  async function cancelVacation(vacation: UserVacation) {
    setVacationError(null);
    setVacationMessage(null);
    try {
      await api.delete(`/vacations/${vacation.id}`);
      setVacationMessage('Vakantie ingetrokken.');
      await vacations.reload();
    } catch (err) {
      setVacationError(err instanceof ApiClientError ? err.message : 'Vakantie kon niet worden ingetrokken.');
    }
  }

  async function submitAsset(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavingAsset(true);
    setAssetError(null);
    setAssetMessage(null);

    const payload = {
      name: assetForm.name,
      type: assetForm.type,
      drone_type_id: assetForm.type === 'drone' ? assetForm.droneTypeId || null : null,
      has_spotlight: assetSupportsSpotlight ? assetForm.hasSpotlight : false,
      has_speaker: assetSupportsSpeaker ? assetForm.hasSpeaker : false,
      status: assetForm.status,
      serial_number: assetForm.serialNumber || null,
      maintenance_due_at: assetForm.maintenanceDueAt || null,
      notes: assetForm.notes || null,
    };

    try {
      if (assetForm.id === null) {
        await api.post<Asset>('/assets/mine', { ...payload, asset_tag: null });
        setAssetMessage('Asset opgeslagen.');
      } else {
        await api.patch<Asset>(`/assets/${assetForm.id}/mine`, payload);
        setAssetMessage('Asset aangepast.');
      }
      setAssetForm(emptyAssetForm());
      await assets.reload();
    } catch (err) {
      setAssetError(err instanceof ApiClientError ? err.message : 'Asset kon niet worden opgeslagen.');
    } finally {
      setSavingAsset(false);
    }
  }

  async function deleteAsset(asset: Asset) {
    setSavingAsset(true);
    setAssetError(null);
    setAssetMessage(null);
    try {
      await api.delete(`/assets/${asset.id}/mine`);
      if (assetForm.id === asset.id) {
        setAssetForm(emptyAssetForm());
      }
      setAssetMessage('Asset verwijderd.');
      await assets.reload();
    } catch (err) {
      setAssetError(err instanceof ApiClientError ? err.message : 'Asset kon niet worden verwijderd.');
    } finally {
      setSavingAsset(false);
    }
  }

  function setAssetFormFromAsset(asset: Asset) {
    setAssetError(null);
    setAssetMessage(null);
    setAssetForm({
      id: asset.id,
      name: asset.name,
      type: asset.type,
      droneTypeId: asset.drone_type_id ?? '',
      hasSpotlight: asset.has_spotlight,
      hasSpeaker: asset.has_speaker,
      status: ownAssetStatus(asset.status),
      serialNumber: asset.serial_number ?? '',
      maintenanceDueAt: normalizeDate(asset.maintenance_due_at),
      notes: asset.notes ?? '',
    });
  }

  async function submitCertification(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSavingCertification(true);
    setCertificationError(null);
    setCertificationMessage(null);

    const payload = {
      certification_id: certificationForm.certificationId,
      issued_at: certificationForm.issuedAt,
      expires_at: certificationForm.expiresAt || null,
      certificate_number: certificationForm.certificateNumber || null,
      status: certificationForm.status,
    };

    try {
      if (certificationForm.id === null) {
        await api.post<UserCertification>('/certifications/me', payload);
        setCertificationMessage('Certificaat opgeslagen.');
      } else {
        await api.patch<UserCertification>(`/certifications/me/${certificationForm.id}`, payload);
        setCertificationMessage('Certificaat aangepast.');
      }
      setCertificationForm(emptyCertificationForm());
      await userCertifications.reload();
    } catch (err) {
      setCertificationError(err instanceof ApiClientError ? err.message : 'Certificaat kon niet worden opgeslagen.');
    } finally {
      setSavingCertification(false);
    }
  }

  async function deleteCertification(certification: UserCertification) {
    setSavingCertification(true);
    setCertificationError(null);
    setCertificationMessage(null);
    try {
      await api.delete(`/certifications/me/${certification.id}`);
      if (certificationForm.id === certification.id) {
        setCertificationForm(emptyCertificationForm());
      }
      setCertificationMessage('Certificaat verwijderd.');
      await userCertifications.reload();
    } catch (err) {
      setCertificationError(err instanceof ApiClientError ? err.message : 'Certificaat kon niet worden verwijderd.');
    } finally {
      setSavingCertification(false);
    }
  }

  function setCertificationFormFromCertification(certification: UserCertification) {
    setCertificationError(null);
    setCertificationMessage(null);
    setCertificationForm({
      id: certification.id,
      certificationId: certification.certification_id,
      issuedAt: normalizeDate(certification.issued_at) || todayInputValue(),
      expiresAt: normalizeDate(certification.expires_at),
      certificateNumber: certification.certificate_number ?? '',
      status: certification.status === 'pending' ? 'active' : certification.status,
    });
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

      <Panel title="Mijn vakantie">
        <form className="form-grid" onSubmit={submitVacation}>
          <label>
            Begindatum
            <input type="date" value={vacationForm.startsAt} onChange={(event) => setVacationForm((current) => ({ ...current, startsAt: event.target.value }))} />
          </label>
          <label>
            Einddatum
            <input type="date" value={vacationForm.endsAt} onChange={(event) => setVacationForm((current) => ({ ...current, endsAt: event.target.value }))} />
          </label>
          <label className="form-grid__wide">
            Notitie
            <input value={vacationForm.note} maxLength={1000} onChange={(event) => setVacationForm((current) => ({ ...current, note: event.target.value }))} />
          </label>
          {vacationError ? <p className="form-error form-grid__wide">{vacationError}</p> : null}
          {vacationMessage ? <p className="form-note form-grid__wide">{vacationMessage}</p> : null}
          <div className="actions-row form-grid__wide">
            <button className="primary-button" type="submit" disabled={savingVacation || vacationForm.startsAt === '' || vacationForm.endsAt === ''}>
              {savingVacation ? 'Opslaan...' : 'Vakantie opgeven'}
            </button>
          </div>
        </form>
        <VacationTable vacations={vacations.data ?? []} loading={vacations.loading} error={vacations.error} onCancel={cancelVacation} />
      </Panel>

      <Panel title="Mijn assets">
        <form className="form-grid" onSubmit={submitAsset}>
          <label>
            Naam
            <input value={assetForm.name} onChange={(event) => setAssetForm((current) => ({ ...current, name: event.target.value }))} required />
          </label>
          <label>
            Type
            <select value={assetForm.type} onChange={(event) => setAssetForm((current) => ({ ...current, type: event.target.value, droneTypeId: '', hasSpotlight: false, hasSpeaker: false }))}>
              <option value="drone">Drone</option>
              <option value="battery">Batterij</option>
              <option value="sensor">Sensor</option>
              <option value="vehicle">Voertuig</option>
              <option value="support_equipment">Ondersteunend materieel</option>
            </select>
          </label>
          {assetForm.type === 'drone' ? (
            <label>
              Drone type
              <select
                value={assetForm.droneTypeId}
                onChange={(event) => {
                  const nextDroneType = droneTypes.data?.find((type) => type.id === event.target.value) ?? null;
                  setAssetForm((current) => ({
                    ...current,
                    droneTypeId: event.target.value,
                    hasSpotlight: nextDroneType?.has_spotlight === true ? current.hasSpotlight : false,
                    hasSpeaker: nextDroneType?.has_speaker === true ? current.hasSpeaker : false,
                  }));
                }}
                required
              >
                <option value="">Kies drone type</option>
                {droneTypes.data?.filter((type) => type.is_active || type.id === assetForm.droneTypeId).map((type) => (
                  <option key={type.id} value={type.id}>{droneTypeLabel(type)}</option>
                ))}
              </select>
            </label>
          ) : null}
          <label>
            Status
            <select value={assetForm.status} onChange={(event) => setAssetForm((current) => ({ ...current, status: event.target.value as OwnAssetStatus }))}>
              <option value="ready">Gereed</option>
              <option value="maintenance">Onderhoud</option>
              <option value="unavailable">Niet beschikbaar</option>
            </select>
          </label>
          <label>
            Serienummer
            <input value={assetForm.serialNumber} onChange={(event) => setAssetForm((current) => ({ ...current, serialNumber: event.target.value }))} />
          </label>
          <label>
            Onderhoud voor
            <input type="date" value={assetForm.maintenanceDueAt} onChange={(event) => setAssetForm((current) => ({ ...current, maintenanceDueAt: event.target.value }))} />
          </label>
          {assetSupportsSpotlight ? (
            <label className="check-label">
              <input type="checkbox" checked={assetForm.hasSpotlight} onChange={(event) => setAssetForm((current) => ({ ...current, hasSpotlight: event.target.checked }))} />
              Externe lamp
            </label>
          ) : null}
          {assetSupportsSpeaker ? (
            <label className="check-label">
              <input type="checkbox" checked={assetForm.hasSpeaker} onChange={(event) => setAssetForm((current) => ({ ...current, hasSpeaker: event.target.checked }))} />
              Speaker
            </label>
          ) : null}
          <label className="form-grid__wide">
            Notities
            <textarea value={assetForm.notes} onChange={(event) => setAssetForm((current) => ({ ...current, notes: event.target.value }))} />
          </label>
          {assetError ? <p className="form-error form-grid__wide">{assetError}</p> : null}
          {assetMessage ? <p className="form-note form-grid__wide">{assetMessage}</p> : null}
          <div className="actions-row form-grid__wide">
            {assetForm.id !== null ? <button className="secondary-button" type="button" onClick={() => setAssetForm(emptyAssetForm())}>Nieuw</button> : null}
            <button className="primary-button" type="submit" disabled={savingAsset || assetForm.name === '' || (assetForm.type === 'drone' && assetForm.droneTypeId === '')}>
              {savingAsset ? 'Opslaan...' : assetForm.id === null ? 'Asset toevoegen' : 'Asset aanpassen'}
            </button>
          </div>
        </form>
        <AssetTable assets={assets.data ?? []} loading={assets.loading || droneTypes.loading} error={assets.error ?? droneTypes.error} onEdit={setAssetFormFromAsset} onDelete={deleteAsset} />
      </Panel>

      <Panel title="Mijn certificaten">
        <form className="form-grid" onSubmit={submitCertification}>
          <label>
            Certificaat
            <select
              value={certificationForm.certificationId}
              onChange={(event) => setCertificationForm((current) => ({ ...current, certificationId: event.target.value }))}
              required
              disabled={certificationForm.id !== null}
            >
              <option value="">Kies certificaat</option>
              {certificationOptions.data?.map((certification) => (
                <option key={certification.id} value={certification.id}>{certification.name}</option>
              ))}
            </select>
          </label>
          <label>
            Afgifte
            <input type="date" value={certificationForm.issuedAt} onChange={(event) => setCertificationForm((current) => ({ ...current, issuedAt: event.target.value }))} required />
          </label>
          <label>
            Verloopt
            <input type="date" value={certificationForm.expiresAt} onChange={(event) => setCertificationForm((current) => ({ ...current, expiresAt: event.target.value }))} />
          </label>
          <label>
            Nummer
            <input value={certificationForm.certificateNumber} onChange={(event) => setCertificationForm((current) => ({ ...current, certificateNumber: event.target.value }))} />
          </label>
          <label>
            Status
            <select value={certificationForm.status} onChange={(event) => setCertificationForm((current) => ({ ...current, status: event.target.value as UserCertification['status'] }))}>
              <option value="active">Actief</option>
              <option value="expired">Verlopen</option>
              <option value="revoked">Ingetrokken</option>
            </select>
          </label>
          {certificationError ? <p className="form-error form-grid__wide">{certificationError}</p> : null}
          {certificationMessage ? <p className="form-note form-grid__wide">{certificationMessage}</p> : null}
          <div className="actions-row form-grid__wide">
            {certificationForm.id !== null ? <button className="secondary-button" type="button" onClick={() => setCertificationForm(emptyCertificationForm())}>Nieuw</button> : null}
            <button className="primary-button" type="submit" disabled={savingCertification || certificationForm.certificationId === '' || certificationForm.issuedAt === ''}>
              {savingCertification ? 'Opslaan...' : certificationForm.id === null ? 'Certificaat toevoegen' : 'Certificaat aanpassen'}
            </button>
          </div>
        </form>
        <CertificationTable
          certifications={userCertifications.data ?? []}
          loading={userCertifications.loading || certificationOptions.loading}
          error={userCertifications.error ?? certificationOptions.error}
          onEdit={setCertificationFormFromCertification}
          onDelete={deleteCertification}
        />
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
              <TotpQrCode value={setup.provisioning_uri} alt="MFA QR-code voor Authenticator app" helpText="Scan deze QR-code met je Authenticator app." />
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

function VacationTable({ vacations, loading, error, onCancel }: { vacations: UserVacation[]; loading: boolean; error: string | null; onCancel: (vacation: UserVacation) => Promise<void> }) {
  return (
    <ResourceState loading={loading} error={error} empty={vacations.length === 0}>
      <table className="data-table">
        <thead>
          <tr>
            <th>Begin</th>
            <th>Einde</th>
            <th>Status</th>
            <th>Notitie</th>
            <th>Actie</th>
          </tr>
        </thead>
        <tbody>
          {vacations.map((vacation) => (
            <tr key={vacation.id}>
              <td>{formatDate(vacation.starts_at)}</td>
              <td>{formatDate(vacation.ends_at)}</td>
              <td><StatusPill value={vacation.status} tone={vacation.status === 'active' ? 'warn' : vacation.status === 'cancelled' ? 'bad' : 'neutral'} /></td>
              <td>{vacation.note ?? '-'}</td>
              <td>
                {vacation.status === 'scheduled' || vacation.status === 'active' ? (
                  <button className="secondary-button" type="button" onClick={() => void onCancel(vacation)}>Intrekken</button>
                ) : '-'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </ResourceState>
  );
}

function AssetTable({
  assets,
  loading,
  error,
  onEdit,
  onDelete,
}: {
  assets: Asset[];
  loading: boolean;
  error: string | null;
  onEdit: (asset: Asset) => void;
  onDelete: (asset: Asset) => Promise<void>;
}) {
  return (
    <ResourceState loading={loading} error={error} empty={assets.length === 0}>
      <table className="data-table">
        <thead>
          <tr>
            <th>Naam</th>
            <th>Type</th>
            <th>Status</th>
            <th>Serienummer</th>
            <th>Onderhoud</th>
            <th>Actie</th>
          </tr>
        </thead>
        <tbody>
          {assets.map((asset) => (
            <tr key={asset.id}>
              <td>{asset.name}</td>
              <td>{asset.drone_type ? droneTypeLabel(asset.drone_type) : assetTypeLabel(asset.type)}</td>
              <td><StatusPill value={assetStatusLabel(asset.status)} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' ? 'warn' : 'neutral'} /></td>
              <td>{asset.serial_number ?? '-'}</td>
              <td>{formatDate(asset.maintenance_due_at)}</td>
              <td>
                <div className="actions-row">
                  <button className="secondary-button" type="button" onClick={() => onEdit(asset)}>Aanpassen</button>
                  <button className="secondary-button" type="button" onClick={() => void onDelete(asset)}><Trash2 size={16} /> Verwijderen</button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </ResourceState>
  );
}

function CertificationTable({
  certifications,
  loading,
  error,
  onEdit,
  onDelete,
}: {
  certifications: UserCertification[];
  loading: boolean;
  error: string | null;
  onEdit: (certification: UserCertification) => void;
  onDelete: (certification: UserCertification) => Promise<void>;
}) {
  return (
    <ResourceState loading={loading} error={error} empty={certifications.length === 0}>
      <table className="data-table">
        <thead>
          <tr>
            <th>Certificaat</th>
            <th>Status</th>
            <th>Nummer</th>
            <th>Afgifte</th>
            <th>Verloopt</th>
            <th>Actie</th>
          </tr>
        </thead>
        <tbody>
          {certifications.map((certification) => (
            <tr key={certification.id}>
              <td>{certification.certification?.name ?? '-'}</td>
              <td><StatusPill value={certificationStatusLabel(certification.status)} tone={certification.status === 'active' ? 'good' : 'warn'} /></td>
              <td>{certification.certificate_number ?? '-'}</td>
              <td>{formatDate(certification.issued_at)}</td>
              <td>{formatDate(certification.expires_at)}</td>
              <td>
                <div className="actions-row">
                  <button className="secondary-button" type="button" onClick={() => onEdit(certification)}>Aanpassen</button>
                  <button className="secondary-button" type="button" onClick={() => void onDelete(certification)}><Trash2 size={16} /> Verwijderen</button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </ResourceState>
  );
}

function todayInputValue(): string {
  const today = new Date();
  const year = today.getFullYear();
  const month = String(today.getMonth() + 1).padStart(2, '0');
  const day = String(today.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

function normalizeDate(value?: string | null): string {
  return dateParts(value)?.input ?? '';
}

function formatDate(value?: string | null): string {
  return dateParts(value)?.display ?? '-';
}

function dateParts(value?: string | null): { input: string; display: string } | null {
  const match = value?.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) {
    return null;
  }

  const [, year, month, day] = match;

  return {
    input: `${year}-${month}-${day}`,
    display: `${day}-${month}-${year}`,
  };
}

function ownAssetStatus(status: Asset['status']): OwnAssetStatus {
  return status === 'maintenance' || status === 'unavailable' ? status : 'ready';
}

function assetTypeLabel(type: string): string {
  switch (type) {
    case 'drone':
      return 'Drone';
    case 'battery':
      return 'Batterij';
    case 'sensor':
      return 'Sensor';
    case 'vehicle':
      return 'Voertuig';
    case 'support_equipment':
      return 'Ondersteunend materieel';
    default:
      return type;
  }
}

function assetStatusLabel(status: Asset['status']): string {
  switch (status) {
    case 'ready':
      return 'Gereed';
    case 'assigned':
      return 'Toegewezen';
    case 'maintenance':
      return 'Onderhoud';
    case 'unavailable':
      return 'Niet beschikbaar';
    case 'retired':
      return 'Uit dienst';
    default:
      return status;
  }
}

function certificationStatusLabel(status: UserCertification['status']): string {
  switch (status) {
    case 'active':
      return 'Actief';
    case 'expired':
      return 'Verlopen';
    case 'revoked':
      return 'Ingetrokken';
    case 'pending':
      return 'In behandeling';
    default:
      return status;
  }
}
