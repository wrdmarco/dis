import { FormEvent, useEffect, useState } from 'react';
import { Pencil, Plus, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { Asset } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

type AssetStatus = Asset['status'];

interface AssetFormState {
  assetTag: string;
  name: string;
  type: string;
  status: AssetStatus;
  serialNumber: string;
  maintenanceDueAt: string;
  notes: string;
}

const emptyForm: AssetFormState = {
  assetTag: '',
  name: '',
  type: 'drone',
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
  const [modalMode, setModalMode] = useState<'create' | 'edit' | null>(null);
  const [editingAsset, setEditingAsset] = useState<Asset | null>(null);
  const [form, setForm] = useState<AssetFormState>(emptyForm);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

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
      assetTag: asset.asset_tag,
      name: asset.name,
      type: asset.type,
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
      asset_tag: form.assetTag,
      name: form.name,
      type: form.type,
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

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void assets.reload()} />
      <Panel
        title="Assets"
        action={(
          <button className="primary-button" type="button" onClick={openCreateModal}>
            <Plus size={16} /> Asset registreren
          </button>
        )}
      >
        <ResourceState loading={assets.loading} error={assets.error} empty={(assets.data?.length ?? 0) === 0}>
          <table className="data-table">
            <thead><tr><th>Tag</th><th>Naam</th><th>Type</th><th>Status</th><th>Serienummer</th><th>Onderhoud</th><th>Actie</th></tr></thead>
            <tbody>
              {assets.data?.map((asset) => (
                <tr key={asset.id}>
                  <td>{asset.asset_tag}</td>
                  <td>{asset.name}</td>
                  <td>{asset.type}</td>
                  <td><StatusPill value={asset.status} tone={asset.status === 'ready' ? 'good' : asset.status === 'maintenance' ? 'warn' : 'neutral'} /></td>
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
                Asset tag
                <input value={form.assetTag} onChange={(event) => setForm((current) => ({ ...current, assetTag: event.target.value }))} required />
              </label>
              <label>
                Naam
                <input value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} required />
              </label>
              <label>
                Type
                <select value={form.type} onChange={(event) => setForm((current) => ({ ...current, type: event.target.value }))}>
                  {assetTypes.map((type) => (
                    <option key={type.value} value={type.value}>{type.label}</option>
                  ))}
                </select>
              </label>
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
                <button className="primary-button" type="submit" disabled={saving}>
                  {saving ? 'Opslaan...' : 'Opslaan'}
                </button>
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
