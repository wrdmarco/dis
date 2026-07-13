'use client';

import { type FormEvent, useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowLeft } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import type { DroneType } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

interface DroneTypeFormState {
  manufacturer: string;
  model: string;
  hasThermal: boolean;
  hasSpotlight: boolean;
  hasSpeaker: boolean;
  isActive: boolean;
  notes: string;
}

export function DroneTypeFormPage({ droneTypeId }: { droneTypeId?: string }) {
  const router = useRouter();
  const { api } = useAuth();
  const isEditing = droneTypeId !== undefined;
  const droneTypes = useApiResource<DroneType[]>('/drone-types', isEditing);
  const selectedDroneType = droneTypes.data?.find((type) => type.id === droneTypeId) ?? null;
  const [form, setForm] = useState<DroneTypeFormState | null>(() => isEditing ? null : createEmptyDroneTypeForm());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (selectedDroneType !== null) {
      setForm(formFromDroneType(selectedDroneType));
    }
  }, [selectedDroneType]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (form === null) {
      return;
    }

    setSaving(true);
    setError(null);

    const payload = {
      manufacturer: form.manufacturer,
      model: form.model,
      has_thermal: form.hasThermal,
      has_spotlight: form.hasSpotlight,
      has_speaker: form.hasSpeaker,
      is_active: form.isActive,
      notes: form.notes || null,
    };

    try {
      if (isEditing) {
        await api.patch(`/admin/drone-types/${droneTypeId}`, payload);
      } else {
        await api.post('/admin/drone-types', payload);
      }
      router.push('/assets');
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Drone type kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  const loading = isEditing && (droneTypes.loading || (selectedDroneType !== null && form === null));

  return (
    <div className="page-stack">
      <Panel
        title={isEditing ? 'Drone type aanpassen' : 'Drone type toevoegen'}
        action={(
          <Link className="secondary-button" href="/assets">
            <ArrowLeft size={16} /> Terug naar assets
          </Link>
        )}
      >
        <ResourceState
          loading={loading}
          error={droneTypes.error}
          empty={isEditing && selectedDroneType === null}
        >
          {form !== null ? (
            <form className="form-grid" onSubmit={submit}>
              <label>
                Merk
                <input value={form.manufacturer} onChange={(event) => setForm((current) => current === null ? current : ({ ...current, manufacturer: event.target.value }))} required />
              </label>
              <label>
                Model
                <input value={form.model} onChange={(event) => setForm((current) => current === null ? current : ({ ...current, model: event.target.value }))} required />
              </label>
              <label className="check-label">
                <input type="checkbox" checked={form.hasThermal} onChange={(event) => setForm((current) => current === null ? current : ({ ...current, hasThermal: event.target.checked }))} />
                Thermal aanwezig
              </label>
              <label className="check-label">
                <input type="checkbox" checked={form.hasSpotlight} onChange={(event) => setForm((current) => current === null ? current : ({ ...current, hasSpotlight: event.target.checked }))} />
                Externe lamp aanwezig
              </label>
              <label className="check-label">
                <input type="checkbox" checked={form.hasSpeaker} onChange={(event) => setForm((current) => current === null ? current : ({ ...current, hasSpeaker: event.target.checked }))} />
                Speaker aanwezig
              </label>
              <label className="check-label">
                <input type="checkbox" checked={form.isActive} onChange={(event) => setForm((current) => current === null ? current : ({ ...current, isActive: event.target.checked }))} />
                Actief
              </label>
              <label className="form-grid__wide">
                Notities
                <textarea value={form.notes} onChange={(event) => setForm((current) => current === null ? current : ({ ...current, notes: event.target.value }))} />
              </label>
              {error ? <p className="form-error form-grid__wide">{error}</p> : null}
              <div className="actions-row form-grid__wide">
                <Link className="secondary-button" href="/assets">Annuleren</Link>
                <button className="primary-button" type="submit" disabled={saving}>{saving ? 'Opslaan...' : 'Opslaan'}</button>
              </div>
            </form>
          ) : null}
        </ResourceState>
      </Panel>
    </div>
  );
}

function createEmptyDroneTypeForm(): DroneTypeFormState {
  return {
    manufacturer: 'DJI',
    model: '',
    hasThermal: false,
    hasSpotlight: false,
    hasSpeaker: false,
    isActive: true,
    notes: '',
  };
}

function formFromDroneType(droneType: DroneType): DroneTypeFormState {
  return {
    manufacturer: droneType.manufacturer,
    model: droneType.model,
    hasThermal: droneType.has_thermal,
    hasSpotlight: droneType.has_spotlight,
    hasSpeaker: droneType.has_speaker,
    isActive: droneType.is_active,
    notes: droneType.notes ?? '',
  };
}
