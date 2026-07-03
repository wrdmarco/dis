import { type FormEvent, useState } from 'react';
import { CalendarDays, Pencil, Trash2, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import type { AvailabilitySchedule, AvailabilityStatus } from '../../types/api';
import { RealtimeBridge } from '../realtime/RealtimeBridge';

export function StatusPage() {
  const { api, hasPermission } = useAuth();
  const statuses = useApiResource<AvailabilityStatus[]>('/status/users?per_page=200');
  const [editingStatus, setEditingStatus] = useState<AvailabilityStatus | null>(null);
  const [status, setStatus] = useState('available');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [scheduleUser, setScheduleUser] = useState<AvailabilityStatus | null>(null);
  const [schedule, setSchedule] = useState<AvailabilitySchedule | null>(null);
  const [scheduleLoading, setScheduleLoading] = useState(false);
  const [scheduleSaving, setScheduleSaving] = useState(false);
  const [scheduleError, setScheduleError] = useState<string | null>(null);
  const [scheduleMessage, setScheduleMessage] = useState<string | null>(null);
  const [overrideStartsAt, setOverrideStartsAt] = useState(() => new Date().toISOString().slice(0, 10));
  const [overrideEndsAt, setOverrideEndsAt] = useState(() => new Date().toISOString().slice(0, 10));
  const [overrideAvailable, setOverrideAvailable] = useState(false);
  const [overrideNote, setOverrideNote] = useState('');
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

  async function openScheduleModal(item: AvailabilityStatus) {
    setScheduleUser(item);
    setSchedule(null);
    setScheduleError(null);
    setScheduleMessage(null);
    setScheduleLoading(true);
    try {
      const response = await api.get<AvailabilitySchedule>(`/status/users/${item.user_id}/availability-schedule`);
      setSchedule(response.data);
    } catch (err) {
      setScheduleError(err instanceof ApiClientError ? err.message : 'Beschikbaarheidsschema kon niet worden geladen.');
    } finally {
      setScheduleLoading(false);
    }
  }

  function updateScheduleDay(dayOfWeek: number, isAvailable: boolean) {
    setSchedule((current) => current === null ? current : {
      ...current,
      week_pattern: current.week_pattern.map((day) => day.day_of_week === dayOfWeek ? { ...day, is_available: isAvailable, source: 'pattern' } : day),
    });
  }

  async function saveWeekPattern() {
    if (schedule === null) {
      return;
    }

    setScheduleSaving(true);
    setScheduleError(null);
    setScheduleMessage(null);
    try {
      const response = await api.patch<AvailabilitySchedule>(`/status/users/${schedule.user_id}/availability-schedule/week-pattern`, {
        patterns: schedule.week_pattern.map((day) => ({
          day_of_week: day.day_of_week,
          is_available: day.is_available,
          note: day.note ?? null,
        })),
      });
      setSchedule(response.data);
      setScheduleMessage('Weekpatroon opgeslagen.');
      await statuses.reload();
    } catch (err) {
      setScheduleError(err instanceof ApiClientError ? err.message : 'Weekpatroon kon niet worden opgeslagen.');
    } finally {
      setScheduleSaving(false);
    }
  }

  async function addOverride(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (schedule === null) {
      return;
    }

    setScheduleSaving(true);
    setScheduleError(null);
    setScheduleMessage(null);
    try {
      const response = await api.post<AvailabilitySchedule>(`/status/users/${schedule.user_id}/availability-schedule/overrides`, {
        starts_at: overrideStartsAt,
        ends_at: overrideEndsAt,
        is_available: overrideAvailable,
        note: overrideNote.trim() === '' ? null : overrideNote,
      });
      setSchedule(response.data);
      setOverrideNote('');
      setScheduleMessage('Uitzondering toegevoegd.');
      await statuses.reload();
    } catch (err) {
      setScheduleError(err instanceof ApiClientError ? err.message : 'Uitzondering kon niet worden toegevoegd.');
    } finally {
      setScheduleSaving(false);
    }
  }

  async function deleteOverride(overrideId: string) {
    if (schedule === null) {
      return;
    }

    setScheduleSaving(true);
    setScheduleError(null);
    setScheduleMessage(null);
    try {
      await api.delete(`/availability-schedule/overrides/${overrideId}`);
      const response = await api.get<AvailabilitySchedule>(`/status/users/${schedule.user_id}/availability-schedule`);
      setSchedule(response.data);
      setScheduleMessage('Uitzondering verwijderd.');
      await statuses.reload();
    } catch (err) {
      setScheduleError(err instanceof ApiClientError ? err.message : 'Uitzondering kon niet worden verwijderd.');
    } finally {
      setScheduleSaving(false);
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
                          <button className="secondary-button" type="button" onClick={() => void openScheduleModal(item)}>
                            <CalendarDays size={16} /> Schema
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

      {scheduleUser !== null && canOverrideStatus ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal" role="dialog" aria-modal="true" aria-labelledby="availability-schedule-title">
            <header className="modal__header">
              <h2 id="availability-schedule-title">Beschikbaarheidsschema</h2>
              <button className="icon-button" type="button" onClick={() => setScheduleUser(null)} aria-label="Sluiten">
                <X size={18} />
              </button>
            </header>
            <div className="panel-body">
              <div className="summary-grid">
                <SummaryItem label="Gebruiker" value={scheduleUser.user?.name ?? '-'} />
                <SummaryItem label="Vandaag" value={schedule?.today.is_available ? 'Beschikbaar' : 'Niet beschikbaar'} />
              </div>
              {scheduleLoading ? <p className="form-note">Schema laden...</p> : null}
              {schedule !== null ? (
                <>
                  <div>
                    <strong>Vast weekpatroon</strong>
                    <div className="checkbox-grid checkbox-grid--dense">
                      {schedule.week_pattern.map((day) => (
                        <label className="checkbox-card" key={day.day_of_week}>
                          <input
                            type="checkbox"
                            checked={day.is_available}
                            onChange={(event) => updateScheduleDay(day.day_of_week, event.target.checked)}
                          />
                          <span>
                            <strong>{dayLabel(day.day_of_week)}</strong>
                            <small>{day.is_available ? 'Beschikbaar' : 'Niet beschikbaar'}</small>
                          </span>
                        </label>
                      ))}
                    </div>
                    <div className="form-actions">
                      <button className="primary-button" type="button" onClick={() => void saveWeekPattern()} disabled={scheduleSaving}>
                        {scheduleSaving ? 'Opslaan...' : 'Weekpatroon opslaan'}
                      </button>
                    </div>
                  </div>

                  <form className="form-grid" onSubmit={addOverride}>
                    <h3 className="form-grid__wide">Uitzondering</h3>
                    <label>
                      Vanaf
                      <input type="date" value={overrideStartsAt} onChange={(event) => setOverrideStartsAt(event.target.value)} required />
                    </label>
                    <label>
                      Tot en met
                      <input type="date" value={overrideEndsAt} onChange={(event) => setOverrideEndsAt(event.target.value)} required />
                    </label>
                    <label>
                      Status
                      <select value={overrideAvailable ? 'available' : 'unavailable'} onChange={(event) => setOverrideAvailable(event.target.value === 'available')}>
                        <option value="unavailable">Niet beschikbaar</option>
                        <option value="available">Beschikbaar</option>
                      </select>
                    </label>
                    <label className="form-grid__wide">
                      Notitie
                      <input value={overrideNote} maxLength={255} onChange={(event) => setOverrideNote(event.target.value)} />
                    </label>
                    <div className="form-actions form-grid__wide">
                      <button className="secondary-button" type="submit" disabled={scheduleSaving}>
                        Uitzondering toevoegen
                      </button>
                    </div>
                  </form>

                  <div>
                    <strong>Geplande uitzonderingen</strong>
                    {schedule.overrides.length > 0 ? (
                      <div className="recipient-list">
                        {schedule.overrides.map((override) => (
                          <article className="recipient-row" key={override.id}>
                            <div className="recipient-row__identity">
                              <strong>{override.is_available ? 'Beschikbaar' : 'Niet beschikbaar'}</strong>
                              <span>{override.starts_at} t/m {override.ends_at}</span>
                              {override.note ? <small>{override.note}</small> : null}
                            </div>
                            <button className="danger-button" type="button" onClick={() => void deleteOverride(override.id)} disabled={scheduleSaving}>
                              <Trash2 size={16} /> Verwijderen
                            </button>
                          </article>
                        ))}
                      </div>
                    ) : (
                      <p className="form-note">Geen uitzonderingen vastgelegd.</p>
                    )}
                  </div>
                </>
              ) : null}
              {scheduleError ? <p className="form-error">{scheduleError}</p> : null}
              {scheduleMessage ? <p className="form-note">{scheduleMessage}</p> : null}
            </div>
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

function dayLabel(dayOfWeek: number): string {
  return ['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'][dayOfWeek - 1] ?? String(dayOfWeek);
}
