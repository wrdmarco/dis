import { type FormEvent, useState } from 'react';
import { Pencil, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { AvailabilityStatus } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function StatusPage() {
  const { api, hasPermission } = useAuth();
  const statuses = useApiResource<AvailabilityStatus[]>('/status/users?per_page=200');
  const [editingStatus, setEditingStatus] = useState<AvailabilityStatus | null>(null);
  const [status, setStatus] = useState('available');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const items = statuses.data ?? [];
  const availableCount = items.filter((item) => item.is_available).length;
  const unavailableCount = items.filter((item) => !item.is_available).length;
  const onSceneCount = items.filter((item) => item.status === 'on_scene').length;
  const canOverrideStatus = hasPermission('status.override');

  function openEditModal(item: AvailabilityStatus) {
    setEditingStatus(item);
    setStatus(item.status === 'vacation' ? 'unavailable' : item.status);
    setReason('');
    setError(null);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (editingStatus === null) {
      return;
    }

    setSaving(true);
    setError(null);
    try {
      await api.post(`/status/users/${editingStatus.user_id}/override`, {
        status,
        reason: reason.trim() === '' ? null : reason,
      });
      setEditingStatus(null);
      await statuses.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Status kon niet worden aangepast.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <RealtimeBridge onOperationalEvent={() => void statuses.silentReload()} />
      <Panel title="Gebruikersstatussen">
        <ResourceState loading={statuses.loading} error={statuses.error} empty={items.length === 0}>
          <div className="status-overview">
            <div className="summary-grid">
              <SummaryItem label="Gebruikers" value={String(items.length)} />
              <SummaryItem label="Beschikbaar" value={String(availableCount)} />
              <SummaryItem label="Niet beschikbaar" value={String(unavailableCount)} />
              <SummaryItem label="Op locatie" value={String(onSceneCount)} />
            </div>
            <table className="data-table">
              <thead>
                <tr>
                  <th>Gebruiker</th>
                  <th>E-mail</th>
                  <th>Status</th>
                  <th>Beschikbaar</th>
                  <th>Laatst gewijzigd</th>
                  <th>Actie</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id}>
                    <td>{item.user?.name ?? '-'}</td>
                    <td>{item.user?.email ?? '-'}</td>
                    <td><StatusPill value={item.status} tone={statusTone(item)} /></td>
                    <td>{item.is_available ? 'Ja' : 'Nee'} </td>
                    <td>{formatDateTime(item.effective_at)}</td>
                    <td>
                      {canOverrideStatus ? (
                        <div className="table-actions">
                          <button className="secondary-button" type="button" onClick={() => openEditModal(item)}>
                            <Pencil size={16} /> Status
                          </button>
                        </div>
                      ) : '-'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </ResourceState>
      </Panel>

      {editingStatus !== null && canOverrideStatus ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="status-edit-title">
            <header className="modal__header">
              <h2 id="status-edit-title">Status aanpassen</h2>
              <button className="icon-button" type="button" onClick={() => setEditingStatus(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <form className="form-grid" onSubmit={submit}>
              <label>
                Gebruiker
                <input value={editingStatus.user?.name ?? '-'} readOnly />
              </label>
              <label>
                E-mail
                <input value={editingStatus.user?.email ?? '-'} readOnly />
              </label>
              <label>
                Status
                <select value={status} onChange={(event) => setStatus(event.target.value)}>
                  <option value="available">Beschikbaar</option>
                  <option value="unavailable">Niet beschikbaar</option>
                  <option value="assigned">Toegewezen</option>
                  <option value="on_scene">Op locatie</option>
                  <option value="resting">Rust</option>
                  <option value="suspended">Geblokkeerd</option>
                </select>
              </label>
              <label className="form-grid__wide">
                Reden
                <input value={reason} maxLength={1000} onChange={(event) => setReason(event.target.value)} />
              </label>
              {error ? <p className="form-error form-grid__wide">{error}</p> : null}
              <div className="actions-row form-grid__wide">
                <button className="secondary-button" type="button" onClick={() => setEditingStatus(null)}>Annuleren</button>
                <button className="primary-button" type="submit" disabled={saving}>{saving ? 'Opslaan...' : 'Opslaan'}</button>
              </div>
            </form>
          </section>
        </div>
      ) : null}

    </div>
  );
}

function SummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function statusTone(item: AvailabilityStatus): 'neutral' | 'good' | 'warn' | 'bad' {
  if (item.status === 'en_route' || item.status === 'on_scene') {
    return 'good';
  }

  if (item.is_available) {
    return 'good';
  }

  if (item.status === 'vacation') {
    return 'warn';
  }

  if (item.status === 'unavailable' || item.status === 'suspended') {
    return 'bad';
  }

  return 'neutral';
}
