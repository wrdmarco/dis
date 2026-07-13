'use client';

import { useEffect, useState } from 'react';
import { Panel } from '../../components/Panel';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { assetDisplayLabel } from '../../lib/assetLabels';
import { formatDateOnly, formatDateTime, todayAmsterdamDateInputValue } from '../../lib/dateTime';
import { uniqueOperatorDevices } from '../../lib/devicePresence';
import { droneTypeLabel } from '../../lib/droneTypes';
import type { Asset, Certification, User, UserVacation } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface UserOperationalDetailsProps {
  user: User | null;
  loading: boolean;
  error: string | null;
  assets: Asset[];
  assetsLoading: boolean;
  assetsError: string | null;
  certifications: Certification[];
  certificationsLoading: boolean;
  certificationsError: string | null;
  canManageAssets: boolean;
  canManageCertifications: boolean;
  canManageVacations: boolean;
  onChanged: () => Promise<void>;
}

export function UserOperationalDetails({
  user,
  loading,
  error,
  assets,
  assetsLoading,
  assetsError,
  certifications: certificationOptions,
  certificationsLoading,
  certificationsError,
  canManageAssets,
  canManageCertifications,
  canManageVacations,
  onChanged,
}: UserOperationalDetailsProps) {
  const { api } = useAuth();
  const userId = user?.id ?? null;
  const userCertifications = user?.certifications ?? [];
  const assetAssignments = user?.asset_assignments ?? [];
  const fcmTokens = uniqueOperatorDevices(user?.fcm_tokens ?? []);
  const assignedAssetIds = new Set(assetAssignments.map((assignment) => assignment.asset_id));
  const availableAssets = assets.filter((asset) => !assignedAssetIds.has(asset.id) && asset.status !== 'assigned' && asset.status !== 'retired');
  const userCertificationIds = new Set(userCertifications.map((certification) => certification.certification_id));
  const availableCertifications = certificationOptions.filter((certification) => !userCertificationIds.has(certification.id));
  const [assetId, setAssetId] = useState('');
  const [certificationId, setCertificationId] = useState('');
  const [issuedAt, setIssuedAt] = useState(todayInputValue);
  const [expiresAt, setExpiresAt] = useState('');
  const [certificateNumber, setCertificateNumber] = useState('');
  const [vacations, setVacations] = useState<UserVacation[]>([]);
  const [vacationsLoading, setVacationsLoading] = useState(false);
  const [vacationStartsAt, setVacationStartsAt] = useState(todayInputValue);
  const [vacationEndsAt, setVacationEndsAt] = useState(todayInputValue);
  const [vacationNote, setVacationNote] = useState('');
  const [linking, setLinking] = useState(false);
  const [vacationError, setVacationError] = useState<string | null>(null);
  const [certificationActionError, setCertificationActionError] = useState<string | null>(null);
  const [assetActionError, setAssetActionError] = useState<string | null>(null);

  useEffect(() => {
    if (!canManageVacations || userId === null) {
      setVacations([]);
      setVacationsLoading(false);
      return;
    }

    let cancelled = false;
    setVacationsLoading(true);
    setVacationError(null);
    api.get<UserVacation[]>(`/users/${userId}/vacations`)
      .then((response) => {
        if (!cancelled) {
          setVacations(response.data);
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setVacationError(err instanceof ApiClientError ? err.message : 'Vakanties laden mislukt.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setVacationsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [api, canManageVacations, userId]);

  async function assignAsset() {
    if (userId === null || assetId === '') {
      return;
    }

    setLinking(true);
    setAssetActionError(null);
    try {
      await api.post(`/assets/${assetId}/assign`, { user_id: userId });
      setAssetId('');
      await onChanged();
    } catch (err) {
      setAssetActionError(err instanceof ApiClientError ? err.message : 'Asset koppelen mislukt.');
    } finally {
      setLinking(false);
    }
  }

  async function assignCertification() {
    if (userId === null || certificationId === '') {
      return;
    }

    setLinking(true);
    setCertificationActionError(null);
    try {
      await api.post(`/users/${userId}/certifications`, {
        certification_id: certificationId,
        issued_at: issuedAt,
        expires_at: expiresAt || null,
        certificate_number: certificateNumber || null,
        status: 'active',
      });
      setCertificationId('');
      setIssuedAt(todayInputValue());
      setExpiresAt('');
      setCertificateNumber('');
      await onChanged();
    } catch (err) {
      setCertificationActionError(err instanceof ApiClientError ? err.message : 'Certificaat koppelen mislukt.');
    } finally {
      setLinking(false);
    }
  }

  async function createVacation() {
    if (userId === null) {
      return;
    }

    setLinking(true);
    setVacationError(null);
    try {
      const response = await api.post<UserVacation>(`/users/${userId}/vacations`, {
        starts_at: vacationStartsAt,
        ends_at: vacationEndsAt,
        note: vacationNote.trim() === '' ? null : vacationNote.trim(),
      });
      setVacations((current) => [...current, response.data].sort((a, b) => a.starts_at.localeCompare(b.starts_at)));
      setVacationStartsAt(todayInputValue());
      setVacationEndsAt(todayInputValue());
      setVacationNote('');
    } catch (err) {
      setVacationError(err instanceof ApiClientError ? err.message : 'Vakantie opslaan mislukt.');
    } finally {
      setLinking(false);
    }
  }

  async function cancelVacation(vacation: UserVacation) {
    setLinking(true);
    setVacationError(null);
    try {
      await api.delete(`/vacations/${vacation.id}`);
      setVacations((current) => current.filter((candidate) => candidate.id !== vacation.id));
    } catch (err) {
      setVacationError(err instanceof ApiClientError ? err.message : 'Vakantie intrekken mislukt.');
    } finally {
      setLinking(false);
    }
  }

  return (
    <>
      {canManageVacations ? (
        <Panel title="Vakanties">
          <div className="panel-body">
            {vacationError ? <p className="form-error" role="alert">{vacationError}</p> : null}
            <div className="inline-form inline-form--compact">
              <label>
                Begindatum
                <input type="date" value={vacationStartsAt} onChange={(event) => setVacationStartsAt(event.target.value)} disabled={userId === null} />
              </label>
              <label>
                Einddatum
                <input type="date" value={vacationEndsAt} onChange={(event) => setVacationEndsAt(event.target.value)} disabled={userId === null} />
              </label>
              <label>
                Notitie
                <input value={vacationNote} maxLength={1000} onChange={(event) => setVacationNote(event.target.value)} disabled={userId === null} />
              </label>
              <button className="primary-button" type="button" disabled={linking || userId === null || vacationStartsAt === '' || vacationEndsAt === ''} onClick={() => void createVacation()}>
                Toevoegen
              </button>
            </div>
            {vacationsLoading ? <p className="muted-text">Vakanties laden...</p> : null}
            {!vacationsLoading && vacations.length === 0 ? <p className="muted-text">Geen open vakanties geregistreerd.</p> : null}
            {vacations.length > 0 ? (
              <table className="data-table compact-table">
                <thead><tr><th scope="col">Begin</th><th scope="col">Eind</th><th scope="col">Status</th><th scope="col">Notitie</th><th scope="col">Actie</th></tr></thead>
                <tbody>
                  {vacations.map((vacation) => (
                    <tr key={vacation.id}>
                      <td>{formatDate(vacation.starts_at)}</td>
                      <td>{formatDate(vacation.ends_at)}</td>
                      <td><StatusPill value={vacation.status} tone={vacation.status === 'active' ? 'warn' : 'neutral'} /></td>
                      <td>{vacation.note ?? '-'}</td>
                      <td>
                        {vacation.status === 'scheduled' || vacation.status === 'active' ? (
                          <button className="secondary-button" type="button" disabled={linking} onClick={() => void cancelVacation(vacation)}>Intrekken</button>
                        ) : '-'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : null}
          </div>
        </Panel>
      ) : null}

      <Panel title="Certificaten">
        <div className="panel-body">
          {canManageCertifications ? (
            <div className="inline-form inline-form--compact">
              <label>
                Certificaat
                <select value={certificationId} onChange={(event) => setCertificationId(event.target.value)} disabled={certificationsLoading || userId === null}>
                  <option value="">Selecteer certificaat</option>
                  {availableCertifications.map((certification) => (
                    <option key={certification.id} value={certification.id}>{certification.name}</option>
                  ))}
                </select>
              </label>
              <label>
                Afgifte
                <input type="date" value={issuedAt} onChange={(event) => setIssuedAt(event.target.value)} />
              </label>
              <label>
                Verloopt
                <input type="date" value={expiresAt} onChange={(event) => setExpiresAt(event.target.value)} />
              </label>
              <label>
                Nummer
                <input value={certificateNumber} onChange={(event) => setCertificateNumber(event.target.value)} />
              </label>
              <button className="primary-button" type="button" disabled={linking || certificationId === '' || userId === null} onClick={() => void assignCertification()}>
                Koppelen
              </button>
            </div>
          ) : null}
          {certificationActionError ? <p className="form-error" role="alert">{certificationActionError}</p> : null}
          {certificationsError ? <p className="form-error">{certificationsError}</p> : null}
          {loading ? <p className="muted-text">Certificaten laden...</p> : null}
          {error ? <p className="form-error">{error}</p> : null}
          {!loading && userCertifications.length === 0 ? <p className="muted-text">Geen certificaten geregistreerd.</p> : null}
          {userCertifications.length > 0 ? (
            <table className="data-table compact-table">
              <thead><tr><th scope="col">Certificaat</th><th scope="col">Status</th><th scope="col">Nummer</th><th scope="col">Verloopt</th></tr></thead>
              <tbody>
                {userCertifications.map((certification) => (
                  <tr key={certification.id}>
                    <td>{certification.certification?.name ?? certification.certification?.code ?? certification.certification_id}</td>
                    <td><StatusPill value={certification.status} tone={certification.status === 'active' ? 'good' : 'warn'} /></td>
                    <td>{certification.certificate_number ?? '-'}</td>
                    <td>{formatDate(certification.expires_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : null}
        </div>
      </Panel>

      <Panel title="Assets">
        <div className="panel-body">
          {canManageAssets ? (
            <div className="inline-form inline-form--compact">
              <label>
                Asset
                <select value={assetId} onChange={(event) => setAssetId(event.target.value)} disabled={assetsLoading || userId === null}>
                  <option value="">Selecteer asset</option>
                  {availableAssets.map((asset) => (
                    <option key={asset.id} value={asset.id}>{assetDisplayLabel(asset)}</option>
                  ))}
                </select>
              </label>
              <button className="primary-button" type="button" disabled={linking || assetId === '' || userId === null} onClick={() => void assignAsset()}>
                Koppelen
              </button>
            </div>
          ) : null}
          {assetActionError ? <p className="form-error" role="alert">{assetActionError}</p> : null}
          {assetsError ? <p className="form-error">{assetsError}</p> : null}
          {loading ? <p className="muted-text">Assets laden...</p> : null}
          {!loading && assetAssignments.length === 0 ? <p className="muted-text">Geen actieve assets toegewezen.</p> : null}
          {assetAssignments.length > 0 ? (
            <table className="data-table compact-table">
              <thead><tr><th scope="col">Asset</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Opties</th><th scope="col">Onderhoud</th><th scope="col">Toegewezen</th></tr></thead>
              <tbody>
                {assetAssignments.map((assignment) => {
                  const asset = assignment.asset;
                  const options = [
                    asset?.drone_type?.has_thermal ? 'Thermal' : null,
                    asset?.has_spotlight ? 'Lamp' : null,
                    asset?.has_speaker ? 'Speaker' : null,
                  ].filter(Boolean).join(', ');

                  return (
                    <tr key={assignment.id}>
                      <td>{asset ? assetDisplayLabel(asset) : '-'}</td>
                      <td>{asset?.drone_type ? droneTypeLabel(asset.drone_type) : asset?.type ?? '-'}</td>
                      <td>{asset ? <StatusPill value={asset.status} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' ? 'warn' : 'neutral'} /> : '-'}</td>
                      <td>{options || '-'}</td>
                      <td>{formatDate(asset?.maintenance_due_at)}</td>
                      <td>{formatDate(assignment.assigned_at)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          ) : null}
        </div>
      </Panel>

      <Panel title="Gekoppelde toestellen">
        <div className="panel-body">
          {loading ? <p className="muted-text">Toestellen laden...</p> : null}
          {!loading && fcmTokens.length === 0 ? <p className="muted-text">Geen toestellen gekoppeld.</p> : null}
          {fcmTokens.length > 0 ? (
            <table className="data-table compact-table">
              <thead><tr><th scope="col">Naam</th><th scope="col">Type</th><th scope="col">Toestel</th><th scope="col">App</th><th scope="col">Status</th><th scope="col">Laatst gezien</th></tr></thead>
              <tbody>
                {fcmTokens.map((token) => (
                  <tr key={token.id}>
                    <td>{token.device_name ?? deviceLabel(token.device_manufacturer, token.device_model, token.device_id)}</td>
                    <td>{deviceTypeLabel(token.device_type)} / {token.client_type ?? 'operator'}</td>
                    <td>{deviceLabel(token.device_manufacturer, token.device_model, token.device_id)}{token.android_version ? ` - Android ${token.android_version}${token.sdk_version ? ` SDK ${token.sdk_version}` : ''}` : ''}</td>
                    <td>{token.app_version ?? '-'}</td>
                    <td><StatusPill value={token.is_online ? 'Online' : token.is_active ? 'Offline' : 'Uitgeschakeld'} tone={token.is_online ? 'good' : token.is_active ? 'neutral' : 'bad'} /></td>
                    <td>{formatDateTime(token.last_seen_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : null}
        </div>
      </Panel>
    </>
  );
}

function deviceLabel(manufacturer?: string | null, model?: string | null, fallback?: string | null): string {
  const label = [manufacturer, model].filter((value) => value !== undefined && value !== null && value !== '').join(' ');

  return label || fallback || '-';
}

function deviceTypeLabel(type?: string | null): string {
  if (type === 'tablet') {
    return 'Tablet';
  }

  if (type === 'phone') {
    return 'Telefoon';
  }

  return 'Onbekend';
}

function formatDate(value?: string | null): string {
  return formatDateOnly(value);
}

function todayInputValue(): string {
  return todayAmsterdamDateInputValue();
}
