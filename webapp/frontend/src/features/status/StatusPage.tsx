import { type FormEvent, type ReactNode, useEffect, useState } from 'react';
import { Clock3, Pencil, ShieldCheck, UsersRound, X } from 'lucide-react';
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
  const statuses = useApiResource<AvailabilityStatus[]>('/availability-statuses/users?per_page=200');
  const [editingStatus, setEditingStatus] = useState<AvailabilityStatus | null>(null);
  const [status, setStatus] = useState('available');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const items = statuses.data ?? [];
  const sortedItems = [...items].sort((left, right) => Number(left.is_available) - Number(right.is_available)
    || (left.next_available_at?.at ?? '').localeCompare(right.next_available_at?.at ?? '')
    || (left.user?.name ?? '').localeCompare(right.user?.name ?? ''));
  const onlineItems = sortedItems.filter((item) => isUserOnline(item));
  const offlineItems = sortedItems.filter((item) => !isUserOnline(item));
  const availableCount = items.filter((item) => item.is_available).length;
  const unavailableCount = items.filter((item) => !item.is_available).length;
  const onlineCount = onlineItems.length;
  const onSceneCount = items.filter((item) => item.status === 'on_scene').length;
  const returningCount = items.filter((item) => !item.is_available && item.next_available_at !== null && item.next_available_at !== undefined).length;
  const canOverrideStatus = hasPermission('status.override');

  useEffect(() => {
    const interval = window.setInterval(() => {
      void statuses.silentReload();
    }, 60_000);

    return () => window.clearInterval(interval);
  }, [statuses.silentReload]);

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
      await api.post(`/availability-statuses/users/${editingStatus.user_id}/override`, {
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
      <Panel title="Operational status">
        <ResourceState loading={statuses.loading} error={statuses.error} empty={items.length === 0}>
          <div className="operational-status">
            <div className="operational-status__summary">
              <SummaryItem icon={<ShieldCheck size={18} />} label="Nu beschikbaar" value={String(availableCount)} />
              <SummaryItem icon={<Clock3 size={18} />} label="Niet beschikbaar" value={String(unavailableCount)} />
              <SummaryItem label="Online" value={String(onlineCount)} />
              <SummaryItem icon={<UsersRound size={18} />} label="Wordt later beschikbaar" value={String(returningCount)} />
              <SummaryItem label="Op locatie" value={String(onSceneCount)} />
            </div>
            <StatusTable title="Online operators" items={onlineItems} canOverrideStatus={canOverrideStatus} onEdit={openEditModal} />
            <StatusTable title="Offline operators" items={offlineItems} canOverrideStatus={canOverrideStatus} onEdit={openEditModal} />
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

function StatusTable({
  title,
  items,
  canOverrideStatus,
  onEdit,
}: {
  title: string;
  items: AvailabilityStatus[];
  canOverrideStatus: boolean;
  onEdit: (item: AvailabilityStatus) => void;
}) {
  return (
    <div className="stacked-section">
      <span className="field-label">{title}</span>
      {items.length === 0 ? <p className="muted-text">Geen gebruikers in deze groep.</p> : (
        <table className="data-table operational-status__table">
          <thead>
            <tr>
              <th>Gebruiker</th>
              <th>Device</th>
              <th>Status</th>
              <th>Weer beschikbaar</th>
              <th>Laatst gewijzigd</th>
              <th>Actie</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <td>
                  <div className="operator-cell">
                    <strong>{item.user?.name ?? '-'}</strong>
                    <span>{teamLabel(item)}</span>
                  </div>
                </td>
                <td>
                  <div className="status-cell">
                    <StatusPill value={isUserOnline(item) ? 'Online' : 'Offline'} tone={isUserOnline(item) ? 'good' : 'neutral'} />
                    <small>{deviceSeenLabel(item)}</small>
                  </div>
                </td>
                <td>
                  <div className="status-cell">
                    <StatusPill value={item.is_available ? 'available' : item.status} tone={statusTone(item)} />
                    {item.reason ? <small>{item.reason}</small> : null}
                  </div>
                </td>
                <td>{nextAvailabilityLabel(item)}</td>
                <td>{formatDateTime(item.effective_at)}</td>
                <td>
                  {canOverrideStatus ? (
                    <div className="table-actions">
                      <button className="secondary-button" type="button" onClick={() => onEdit(item)}>
                        <Pencil size={16} /> Status
                      </button>
                    </div>
                  ) : '-'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

function isUserOnline(item: AvailabilityStatus): boolean {
  return item.user?.fcm_tokens?.some((token) => token.client_type !== 'admin' && token.is_online) ?? false;
}

function deviceSeenLabel(item: AvailabilityStatus): string {
  const token = [...(item.user?.fcm_tokens ?? [])]
    .filter((candidate) => candidate.client_type !== 'admin')
    .sort((left, right) => (right.last_seen_at ?? '').localeCompare(left.last_seen_at ?? ''))[0];

  if (token === undefined) {
    return 'Geen operator-device';
  }

  const hardwareName = [token.device_manufacturer, token.device_model].filter(Boolean).join(' ');
  const name = token.device_name ?? (hardwareName || token.device_id);

  return `${name} - ${formatDateTime(token.last_seen_at)}`;
}

function SummaryItem({ icon, label, value }: { icon?: ReactNode; label: string; value: string }) {
  return (
    <div>
      {icon}
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

function nextAvailabilityLabel(item: AvailabilityStatus): string {
  if (item.is_available) {
    return 'Nu beschikbaar';
  }

  if (!item.is_available && item.next_available_at !== null && item.next_available_at !== undefined) {
    return formatDateTime(item.next_available_at.at);
  }

  const next = item.next_availability_change;
  if (next === null || next === undefined) {
    return 'Geen planning bekend';
  }

  if (!next.is_available) {
    return 'Geen beschikbaar moment bekend';
  }

  return formatDateTime(next.at);
}

function teamLabel(item: AvailabilityStatus): string {
  const teams = item.user?.teams ?? [];
  if (teams.length === 0) {
    return item.user?.home_city ?? '';
  }

  return teams.map((team) => team.code || team.name).join(', ');
}
