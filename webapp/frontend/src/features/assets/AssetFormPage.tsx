'use client';

import { type FormEvent, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { droneTypeLabel } from '../../lib/droneTypes';
import { useApiResource } from '../../lib/useApiResource';
import type { Asset, DroneType } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

type AssetStatus = Asset['status'];

interface AssetFormState {
  name: string;
  type: string;
  droneTypeId: string;
  hasSpotlight: boolean;
  hasSpeaker: boolean;
  status: AssetStatus;
  serialNumber: string;
  maintenanceDueAt: string;
  notes: string;
}

const assetTypes = [
  { value: 'drone', label: 'Drone' },
  { value: 'battery', label: 'Batterij' },
  { value: 'sensor', label: 'Sensor' },
  { value: 'vehicle', label: 'Voertuig' },
  { value: 'support_equipment', label: 'Ondersteunend materieel' },
];

const assetStatuses: Array<{ value: AssetStatus; label: string }> = [
  { value: 'ready', label: 'Gereed' },
  { value: 'assigned', label: 'Toegewezen' },
  { value: 'maintenance', label: 'Onderhoud' },
  { value: 'unavailable', label: 'Niet beschikbaar' },
  { value: 'retired', label: 'Uit dienst' },
];

export function AssetFormPage({ assetId }: { assetId?: string }) {
  const router = useRouter();
  const { api } = useAuth();
  const isEditing = assetId !== undefined;
  const asset = useApiResource<Asset>(`/assets/${assetId ?? ''}`, isEditing);
  const droneTypes = useApiResource<DroneType[]>('/drone-types');
  const [form, setForm] = useState<AssetFormState | null>(() => isEditing ? null : createEmptyAssetForm());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (asset.data !== null && asset.data.id === assetId) {
      setForm(formFromAsset(asset.data));
    }
  }, [asset.data, assetId]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (form === null) {
      return;
    }

    setSaving(true);
    setError(null);

    const payload = {
      name: form.name,
      type: form.type,
      drone_type_id: form.type === 'drone' ? form.droneTypeId || null : null,
      has_spotlight: form.type === 'drone' ? form.hasSpotlight : false,
      has_speaker: form.type === 'drone' ? form.hasSpeaker : false,
      status: form.status,
      serial_number: form.serialNumber || null,
      maintenance_due_at: form.maintenanceDueAt || null,
      notes: form.notes || null,
    };

    try {
      if (isEditing) {
        await api.patch(`/assets/${assetId}`, payload);
      } else {
        await api.post('/assets', payload);
      }
      router.push('/assets');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Asset kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  async function deleteAsset() {
    if (asset.data === null || !window.confirm(`${asset.data.name} verwijderen?`)) {
      return;
    }

    setSaving(true);
    setError(null);
    try {
      await api.delete(`/assets/${asset.data.id}`);
      router.push('/assets');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Asset kon niet worden verwijderd.');
    } finally {
      setSaving(false);
    }
  }

  const loading = droneTypes.loading || (isEditing && (asset.loading || (asset.data !== null && form === null)));

  return (
    <div className="page-stack">
      <Panel
        title={isEditing ? 'Asset aanpassen' : 'Asset registreren'}
        action={(
          <Link className="secondary-button" href="/assets">
            <ArrowLeft size={16} /> Terug naar assets
          </Link>
        )}
      >
        <ResourceState
          loading={loading}
          error={asset.error ?? droneTypes.error}
          empty={isEditing && asset.data === null}
        >
          {form !== null ? (
            <AssetForm
              form={form}
              droneTypes={droneTypes.data ?? []}
              isEditing={isEditing}
              saving={saving}
              error={error}
              onChange={setForm}
              onDelete={isEditing ? () => void deleteAsset() : undefined}
              onSubmit={submit}
            />
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}

function AssetForm({
  form,
  droneTypes,
  isEditing,
  saving,
  error,
  onChange,
  onDelete,
  onSubmit,
}: {
  form: AssetFormState;
  droneTypes: DroneType[];
  isEditing: boolean;
  saving: boolean;
  error: string | null;
  onChange: React.Dispatch<React.SetStateAction<AssetFormState | null>>;
  onDelete?: () => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}) {
  const selectedDroneType = droneTypes.find((type) => type.id === form.droneTypeId) ?? null;
  const updateForm = (updater: (current: AssetFormState) => AssetFormState) => {
    onChange((current) => current === null ? current : updater(current));
  };

  return (
    <form className="form-grid" onSubmit={onSubmit}>
      <label>
        Naam
        <input value={form.name} onChange={(event) => updateForm((current) => ({ ...current, name: event.target.value }))} required />
      </label>
      <label>
        Type
        <select value={form.type} onChange={(event) => updateForm((current) => ({ ...current, type: event.target.value, hasSpotlight: false, hasSpeaker: false }))}>
          {assetTypes.map((type) => (
            <option key={type.value} value={type.value}>{type.label}</option>
          ))}
        </select>
      </label>
      {form.type === 'drone' ? (
        <label>
          Drone type
          <select
            value={form.droneTypeId}
            onChange={(event) => {
              const nextDroneType = droneTypes.find((type) => type.id === event.target.value) ?? null;
              updateForm((current) => ({
                ...current,
                droneTypeId: event.target.value,
                hasSpotlight: nextDroneType?.has_spotlight === true ? current.hasSpotlight : false,
                hasSpeaker: nextDroneType?.has_speaker === true ? current.hasSpeaker : false,
              }));
            }}
            required
            disabled={isEditing}
          >
            <option value="">Kies drone type</option>
            {droneTypes.filter((type) => type.is_active || type.id === form.droneTypeId).map((type) => (
              <option key={type.id} value={type.id}>{droneTypeLabel(type)}</option>
            ))}
          </select>
        </label>
      ) : null}
      {form.type === 'drone' && selectedDroneType?.has_spotlight ? (
        <label className="check-label">
          <input type="checkbox" checked={form.hasSpotlight} onChange={(event) => updateForm((current) => ({ ...current, hasSpotlight: event.target.checked }))} />
          Externe lamp
        </label>
      ) : null}
      {form.type === 'drone' && selectedDroneType?.has_speaker ? (
        <label className="check-label">
          <input type="checkbox" checked={form.hasSpeaker} onChange={(event) => updateForm((current) => ({ ...current, hasSpeaker: event.target.checked }))} />
          Speaker
        </label>
      ) : null}
      <label>
        Status
        <select value={form.status} onChange={(event) => updateForm((current) => ({ ...current, status: event.target.value as AssetStatus }))}>
          {assetStatuses.map((status) => (
            <option key={status.value} value={status.value}>{status.label}</option>
          ))}
        </select>
      </label>
      <label>
        Serienummer
        <input value={form.serialNumber} onChange={(event) => updateForm((current) => ({ ...current, serialNumber: event.target.value }))} />
      </label>
      <label>
        Onderhoud voor
        <input type="date" value={form.maintenanceDueAt} onChange={(event) => updateForm((current) => ({ ...current, maintenanceDueAt: event.target.value }))} />
      </label>
      <label className="form-grid__wide">
        Notities
        <textarea value={form.notes} onChange={(event) => updateForm((current) => ({ ...current, notes: event.target.value }))} />
      </label>
      {error ? <p className="form-error form-grid__wide">{error}</p> : null}
      <div className="actions-row form-grid__wide">
        <Link className="secondary-button" href="/assets">Annuleren</Link>
        {onDelete ? (
          <button className="secondary-button" type="button" onClick={onDelete} disabled={saving}>
            Verwijderen
          </button>
        ) : null}
        <button className="primary-button" type="submit" disabled={saving}>
          {saving ? 'Opslaan...' : 'Opslaan'}
        </button>
      </div>
    </form>
  );
}

function createEmptyAssetForm(): AssetFormState {
  return {
    name: '',
    type: 'drone',
    droneTypeId: '',
    hasSpotlight: false,
    hasSpeaker: false,
    status: 'ready',
    serialNumber: '',
    maintenanceDueAt: '',
    notes: '',
  };
}

function formFromAsset(asset: Asset): AssetFormState {
  return {
    name: asset.name,
    type: asset.type,
    droneTypeId: asset.drone_type_id ?? '',
    hasSpotlight: asset.has_spotlight,
    hasSpeaker: asset.has_speaker,
    status: asset.status,
    serialNumber: asset.serial_number ?? '',
    maintenanceDueAt: normalizeDate(asset.maintenance_due_at),
    notes: asset.notes ?? '',
  };
}

function normalizeDate(value?: string | null): string {
  return value ? value.slice(0, 10) : '';
}
