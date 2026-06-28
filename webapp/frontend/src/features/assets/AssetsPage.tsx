import { FormEvent, useEffect, useState } from 'react';
import { Pencil, Plus, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Asset, DroneType } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

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

const emptyForm: AssetFormState = {
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

export function AssetsPage() {
  const { api } = useAuth();
  const assets = useApiResource<Asset[]>('/assets');
  const droneTypes = useApiResource<DroneType[]>('/drone-types');
  const [modalMode, setModalMode] = useState<'create' | 'edit' | null>(null);
  const [droneTypeModalMode, setDroneTypeModalMode] = useState<'create' | 'edit' | null>(null);
  const [editingAsset, setEditingAsset] = useState<Asset | null>(null);
  const [editingDroneType, setEditingDroneType] = useState<DroneType | null>(null);
  const [form, setForm] = useState<AssetFormState>(emptyForm);
  const [droneTypeForm, setDroneTypeForm] = useState({
    manufacturer: 'DJI',
    model: '',
    hasThermal: false,
    hasSpotlight: false,
    hasSpeaker: false,
    isActive: true,
    notes: '',
  });
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const assetList = assets.data ?? [];
  const readyAssets = assetList.filter((asset) => asset.status === 'ready');
  const readyDrones = readyAssets.filter((asset) => asset.type === 'drone');
  const assignedAssets = assetList.filter((asset) => asset.status === 'assigned');
  const maintenanceAssets = assetList.filter((asset) => asset.status === 'maintenance');
  const unavailableAssets = assetList.filter((asset) => asset.status === 'unavailable' || asset.status === 'retired');
  const selectedDroneType = droneTypes.data?.find((type) => type.id === form.droneTypeId) ?? null;

  useEffect(() => {
    if (modalMode === null) {
      setForm(emptyForm);
      setEditingAsset(null);
      setError(null);
    }
  }, [modalMode]);

  function openCreateModal() {
    setEditingAsset(null);
    setForm(emptyForm);
    setError(null);
    setModalMode('create');
  }

  function openEditModal(asset: Asset) {
    setEditingAsset(asset);
    setForm({
      name: asset.name,
      type: asset.type,
      droneTypeId: asset.drone_type_id ?? '',
      hasSpotlight: asset.has_spotlight,
      hasSpeaker: asset.has_speaker,
      status: asset.status,
      serialNumber: asset.serial_number ?? '',
      maintenanceDueAt: normalizeDate(asset.maintenance_due_at),
      notes: asset.notes ?? '',
    });
    setError(null);
    setModalMode('edit');
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
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
      if (modalMode === 'edit' && editingAsset !== null) {
        await api.patch(`/assets/${editingAsset.id}`, payload);
      } else {
        await api.post('/assets', payload);
      }
      setModalMode(null);
      await assets.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Asset kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  function openDroneTypeCreateModal() {
    setEditingDroneType(null);
    setDroneTypeForm({ manufacturer: 'DJI', model: '', hasThermal: false, hasSpotlight: false, hasSpeaker: false, isActive: true, notes: '' });
    setError(null);
    setDroneTypeModalMode('create');
  }

  function openDroneTypeEditModal(droneType: DroneType) {
    setEditingDroneType(droneType);
    setDroneTypeForm({
      manufacturer: droneType.manufacturer,
      model: droneType.model,
      hasThermal: droneType.has_thermal,
      hasSpotlight: droneType.has_spotlight,
      hasSpeaker: droneType.has_speaker,
      isActive: droneType.is_active,
      notes: droneType.notes ?? '',
    });
    setError(null);
    setDroneTypeModalMode('edit');
  }

  async function submitDroneType(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError(null);

    const payload = {
      manufacturer: droneTypeForm.manufacturer,
      model: droneTypeForm.model,
      has_thermal: droneTypeForm.hasThermal,
      has_spotlight: droneTypeForm.hasSpotlight,
      has_speaker: droneTypeForm.hasSpeaker,
      is_active: droneTypeForm.isActive,
      notes: droneTypeForm.notes || null,
    };

    try {
      if (droneTypeModalMode === 'edit' && editingDroneType !== null) {
        await api.patch(`/admin/drone-types/${editingDroneType.id}`, payload);
      } else {
        await api.post('/admin/drone-types', payload);
      }
      setDroneTypeModalMode(null);
      await droneTypes.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Drone type kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  async function deleteDroneType(droneType: DroneType) {
    if (!window.confirm(`${droneType.model} verwijderen?`)) {
      return;
    }

    setSaving(true);
    setError(null);
    try {
      await api.delete(`/admin/drone-types/${droneType.id}`);
      await droneTypes.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Drone type kon niet worden verwijderd.');
    } finally {
      setSaving(false);
    }
  }

  async function deleteAsset(asset: Asset) {
    if (!window.confirm(`${asset.name} verwijderen?`)) {
      return;
    }

    setSaving(true);
    setError(null);
    try {
      await api.delete(`/assets/${asset.id}`);
      setModalMode(null);
      await assets.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Asset kon niet worden verwijderd.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void assets.reload()} />

      <Panel title="Beschikbaarheid">
        <ResourceState loading={assets.loading} error={assets.error} empty={assetList.length === 0}>
          <div className="summary-grid">
            <SummaryItem label="Totaal assets" value={assetList.length} />
            <SummaryItem label="Beschikbaar" value={readyAssets.length} />
            <SummaryItem label="Beschikbare drones" value={readyDrones.length} />
            <SummaryItem label="Toegewezen" value={assignedAssets.length} />
            <SummaryItem label="Onderhoud" value={maintenanceAssets.length} />
            <SummaryItem label="Niet inzetbaar" value={unavailableAssets.length} />
          </div>
        </ResourceState>
      </Panel>

      <Panel
        title="Asset overzicht"
        action={(
          <button className="primary-button" type="button" onClick={openCreateModal}>
            <Plus size={16} /> Asset registreren
          </button>
        )}
      >
        <ResourceState loading={assets.loading} error={assets.error} empty={(assets.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Naam</th><th>Type</th><th>Status</th><th>Toegewezen aan</th><th>Serienummer</th><th>Onderhoud</th><th>Actie</th></tr></thead>
            <tbody>
              {assets.data?.map((asset) => (
                <tr key={asset.id}>
                  <td>{asset.name}</td>
                  <td>{asset.drone_type?.model ?? asset.type}</td>
                  <td><StatusPill value={asset.status} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' ? 'warn' : 'neutral'} /></td>
                  <td>{asset.active_assignment?.user?.name ?? asset.active_assignment?.user?.email ?? '-'}</td>
                  <td>{asset.serial_number ?? '-'}</td>
                  <td>{asset.maintenance_due_at ?? '-'}</td>
                  <td>
                    <button className="secondary-button" type="button" onClick={() => openEditModal(asset)}>
                      <Pencil size={16} /> Aanpassen
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>

      {modalMode !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="asset-modal-title">
            <header className="modal__header">
              <h2 id="asset-modal-title">{modalMode === 'edit' ? 'Asset aanpassen' : 'Asset registreren'}</h2>
              <button className="icon-button" type="button" onClick={() => setModalMode(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <form className="form-grid" onSubmit={submit}>
              <label>
                Naam
                <input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required />
              </label>
              <label>
                Type
                  <select value={form.type} onChange={(event) => setForm((current) => ({ ...current, type: event.target.value, hasSpotlight: false, hasSpeaker: false }))}>
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
                      const nextDroneType = droneTypes.data?.find((type) => type.id === event.target.value) ?? null;
                      setForm((current) => ({
                        ...current,
                        droneTypeId: event.target.value,
                        hasSpotlight: nextDroneType?.has_spotlight === true ? current.hasSpotlight : false,
                        hasSpeaker: nextDroneType?.has_speaker === true ? current.hasSpeaker : false,
                      }));
                    }}
                    required
                    disabled={modalMode === 'edit'}
                  >
                    <option value="">Kies drone type</option>
                    {droneTypes.data?.filter((type) => type.is_active || type.id === form.droneTypeId).map((type) => (
                      <option key={type.id} value={type.id}>{type.manufacturer} {type.model}</option>
                    ))}
                  </select>
                </label>
              ) : null}
              {form.type === 'drone' && selectedDroneType?.has_spotlight ? (
                <label className="check-label">
                  <input type="checkbox" checked={form.hasSpotlight} onChange={(event) => setForm((current) => ({ ...current, hasSpotlight: event.target.checked }))} />
                  Externe lamp
                </label>
              ) : null}
              {form.type === 'drone' && selectedDroneType?.has_speaker ? (
                <label className="check-label">
                  <input type="checkbox" checked={form.hasSpeaker} onChange={(event) => setForm((current) => ({ ...current, hasSpeaker: event.target.checked }))} />
                  Speaker
                </label>
              ) : null}
              <label>
                Status
                <select value={form.status} onChange={(event) => setForm((current) => ({ ...current, status: event.target.value as AssetStatus }))}>
                  {assetStatuses.map((status) => (
                    <option key={status.value} value={status.value}>{status.label}</option>
                  ))}
                </select>
              </label>
              <label>
                Serienummer
                <input value={form.serialNumber} onChange={(event) => setForm((current) => ({ ...current, serialNumber: event.target.value }))} />
              </label>
              <label>
                Onderhoud voor
                <input type="date" value={form.maintenanceDueAt} onChange={(event) => setForm((current) => ({ ...current, maintenanceDueAt: event.target.value }))} />
              </label>
              <label className="form-grid__wide">
                Notities
                <textarea value={form.notes} onChange={(event) => setForm((current) => ({ ...current, notes: event.target.value }))} />
              </label>
              {error ? <p className="form-error form-grid__wide">{error}</p> : null}
              <div className="actions-row form-grid__wide">
                <button className="secondary-button" type="button" onClick={() => setModalMode(null)}>Annuleren</button>
                {modalMode === 'edit' && editingAsset !== null ? (
                  <button className="secondary-button" type="button" onClick={() => void deleteAsset(editingAsset)} disabled={saving}>
                    Verwijderen
                  </button>
                ) : null}
                <button className="primary-button" type="submit" disabled={saving}>
                  {saving ? 'Opslaan...' : 'Opslaan'}
                </button>
              </div>
            </form>
          </section>
        </div>
      ) : null}

      <Panel
        title="Drone types"
        action={(
          <button className="primary-button" type="button" onClick={openDroneTypeCreateModal}>
            <Plus size={16} /> Drone type toevoegen
          </button>
        )}
      >
        {error ? <p className="form-error">{error}</p> : null}
        <ResourceState loading={droneTypes.loading} error={droneTypes.error} empty={(droneTypes.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Merk</th><th>Model</th><th>Thermal</th><th>Externe lamp</th><th>Speaker</th><th>Status</th><th>Actie</th></tr></thead>
            <tbody>
              {droneTypes.data?.map((type) => (
                <tr key={type.id}>
                  <td>{type.manufacturer}</td>
                  <td>{type.model}</td>
                  <td>{type.has_thermal ? 'Ja' : 'Nee'}</td>
                  <td>{type.has_spotlight ? 'Ja' : 'Nee'}</td>
                  <td>{type.has_speaker ? 'Ja' : 'Nee'}</td>
                  <td>{type.is_active ? 'Actief' : 'Uitgeschakeld'}</td>
                  <td>
                    <div className="actions-row">
                      <button className="secondary-button" type="button" onClick={() => openDroneTypeEditModal(type)}>
                        <Pencil size={16} /> Aanpassen
                      </button>
                      <button className="secondary-button" type="button" onClick={() => void deleteDroneType(type)} disabled={saving}>
                        Verwijderen
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>

      {droneTypeModalMode !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="drone-type-modal-title">
            <header className="modal__header">
              <h2 id="drone-type-modal-title">{droneTypeModalMode === 'edit' ? 'Drone type aanpassen' : 'Drone type toevoegen'}</h2>
              <button className="icon-button" type="button" onClick={() => setDroneTypeModalMode(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <form className="form-grid" onSubmit={submitDroneType}>
              <label>
                Merk
                <input value={droneTypeForm.manufacturer} onChange={(event) => setDroneTypeForm((current) => ({ ...current, manufacturer: event.target.value }))} required />
              </label>
              <label>
                Model
                <input value={droneTypeForm.model} onChange={(event) => setDroneTypeForm((current) => ({ ...current, model: event.target.value }))} required />
              </label>
              <label className="check-label">
                <input type="checkbox" checked={droneTypeForm.hasThermal} onChange={(event) => setDroneTypeForm((current) => ({ ...current, hasThermal: event.target.checked }))} />
                Thermal aanwezig
              </label>
              <label className="check-label">
                <input type="checkbox" checked={droneTypeForm.hasSpotlight} onChange={(event) => setDroneTypeForm((current) => ({ ...current, hasSpotlight: event.target.checked }))} />
                Externe lamp aanwezig
              </label>
              <label className="check-label">
                <input type="checkbox" checked={droneTypeForm.hasSpeaker} onChange={(event) => setDroneTypeForm((current) => ({ ...current, hasSpeaker: event.target.checked }))} />
                Speaker aanwezig
              </label>
              <label className="check-label">
                <input type="checkbox" checked={droneTypeForm.isActive} onChange={(event) => setDroneTypeForm((current) => ({ ...current, isActive: event.target.checked }))} />
                Actief
              </label>
              <label className="form-grid__wide">
                Notities
                <textarea value={droneTypeForm.notes} onChange={(event) => setDroneTypeForm((current) => ({ ...current, notes: event.target.value }))} />
              </label>
              <div className="actions-row form-grid__wide">
                <button className="secondary-button" type="button" onClick={() => setDroneTypeModalMode(null)}>Annuleren</button>
                <button className="primary-button" type="submit" disabled={saving}>{saving ? 'Opslaan...' : 'Opslaan'}</button>
              </div>
            </form>
          </section>
        </div>
      ) : null}
    </div>
  );
}

function normalizeDate(value?: string | null): string {
  return value ? value.slice(0, 10) : '';
}

function SummaryItem({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function droneTypeCapabilities(type: DroneType): string {
  const capabilities = [
    type.has_thermal ? 'Thermal' : 'Geen thermal',
    type.has_spotlight ? 'Externe lamp' : 'Geen externe lamp',
    type.has_speaker ? 'Speaker' : 'Geen speaker',
  ];

  return capabilities.join(' / ');
}
