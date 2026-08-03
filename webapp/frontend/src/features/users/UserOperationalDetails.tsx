'use client';

import { useState } from 'react';
import { Panel } from '../../components/Panel';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { assetDisplayLabel, assetTypeLabel } from '../../lib/assetLabels';
import { assetIsEffectivelyReady, assetStatusPresentation } from '../../lib/assetStatus';
import { formatDateOnly, formatDateTime, todayAmsterdamDateInputValue } from '../../lib/dateTime';
import { uniqueOperatorDevices } from '../../lib/devicePresence';
import { droneTypeLabel } from '../../lib/droneTypes';
import type { Asset, Certification, User } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { VacationPlanner } from '../vacations/VacationPlanner';
import { UserAvailabilitySchedule } from './UserAvailabilitySchedule';

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
  canViewAvailabilitySchedule: boolean;
  canManageAvailabilitySchedule: boolean;
  canViewVacations: boolean;
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
  canViewAvailabilitySchedule,
  canManageAvailabilitySchedule,
  canViewVacations,
  canManageVacations,
  onChanged,
}: UserOperationalDetailsProps) {
  const { api } = useAuth();
  const userId = user?.id ?? null;
  const userCertifications = user?.certifications ?? [];
  const assetAssignments = user?.asset_assignments ?? [];
  const fcmTokens = uniqueOperatorDevices(user?.fcm_tokens ?? []);
  const availableAssets = assets.filter((asset) => asset.active_assignment == null && assetIsEffectivelyReady(asset));
  const userCertificationIds = new Set(userCertifications.map((certification) => certification.certification_id));
  const availableCertifications = certificationOptions.filter((certification) => !userCertificationIds.has(certification.id));
  const [assetId, setAssetId] = useState('');
  const [certificationId, setCertificationId] = useState('');
  const [issuedAt, setIssuedAt] = useState(todayInputValue);
  const [expiresAt, setExpiresAt] = useState('');
  const [certificateNumber, setCertificateNumber] = useState('');
  const [linking, setLinking] = useState(false);
  const [certificationActionError, setCertificationActionError] = useState<string | null>(null);
  const [assetActionError, setAssetActionError] = useState<string | null>(null);
  const [availabilityScheduleVersion, setAvailabilityScheduleVersion] = useState(0);

  async function handleVacationChanged() {
    setAvailabilityScheduleVersion((current) => current + 1);
    await onChanged();
  }

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

  return (
    <>
      <UserAvailabilitySchedule
        userId={userId ?? undefined}
        canView={canViewAvailabilitySchedule}
        canManage={canManageAvailabilitySchedule}
        refreshVersion={availabilityScheduleVersion}
      />

      <VacationPlanner
        scope="user"
        userId={userId ?? undefined}
        canView={canViewVacations}
        canManage={canManageVacations}
        onChanged={handleVacationChanged}
      />

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
                  const status = asset ? assetStatusPresentation(asset) : null;
                  const options = [
                    asset?.drone_type?.has_thermal ? 'Thermal' : null,
                    asset?.has_spotlight ? 'Lamp' : null,
                    asset?.has_speaker ? 'Speaker' : null,
                  ].filter(Boolean).join(', ');

                  return (
                    <tr key={assignment.id}>
                      <td>{asset ? assetDisplayLabel(asset) : '-'}</td>
                      <td>{asset?.drone_type ? droneTypeLabel(asset.drone_type) : asset ? assetTypeLabel(asset.type) : '-'}</td>
                      <td>{status ? <StatusPill value={status.label} tone={status.tone} /> : '-'}</td>
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
                    <td>
                      <StatusPill
                        value={token.is_online ? 'Online' : token.is_reachable ? 'Stand-by' : token.is_active ? 'Offline' : 'Uitgeschakeld'}
                        tone={token.is_online ? 'good' : token.is_reachable ? 'neutral' : token.is_active ? 'bad' : 'neutral'}
                      />
                    </td>
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
