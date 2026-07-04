import { type FormEvent, useMemo, useState } from 'react';
import { CalendarDays, Plus } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { CalendarEvent, Team } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

const eventTypes = [
  { value: 'training', label: 'Training' },
  { value: 'open_day', label: 'Open dag' },
  { value: 'exercise', label: 'Oefening' },
  { value: 'meeting', label: 'Overleg' },
  { value: 'other', label: 'Overig' },
] as const;

const initialForm = {
  title: '',
  type: 'training',
  startsAt: '',
  endsAt: '',
  locationLabel: '',
  description: '',
  teamId: '',
};

export function CalendarPage() {
  const { api, hasPermission } = useAuth();
  const canManageAgenda = hasPermission('settings.manage');
  const events = useApiResource<CalendarEvent[]>('/calendar-events');
  const teams = useApiResource<Team[]>('/teams', canManageAgenda);
  const upcoming = useMemo(() => [...(events.data ?? [])].sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime()), [events.data]);
  const [form, setForm] = useState(initialForm);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setMessage(null);
    setError(null);

    try {
      await api.post<CalendarEvent>('/calendar-events', {
        title: form.title.trim(),
        type: form.type,
        starts_at: form.startsAt,
        ends_at: form.endsAt || null,
        location_label: form.locationLabel.trim() || null,
        description: form.description.trim() || null,
        team_id: form.teamId || null,
      });
      setForm(initialForm);
      setMessage('Agenda-item toegevoegd.');
      await events.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Agenda-item opslaan mislukt.');
    } finally {
      setSaving(false);
    }
  }

  async function deleteEvent(eventId: string) {
    if (!window.confirm('Agenda-item verwijderen?')) {
      return;
    }

    setMessage(null);
    setError(null);
    try {
      await api.delete(`/calendar-events/${eventId}`);
      setMessage('Agenda-item verwijderd.');
      await events.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Agenda-item verwijderen mislukt.');
    }
  }

  return (
    <div className="page-stack">
      <Panel title="Algemene agenda">
        <div className="test-alert-hero">
          <div className="test-alert-hero__icon"><CalendarDays size={28} /></div>
          <div>
            <h3>Trainingen, open dagen en teammomenten</h3>
            <p>Deze agenda is zichtbaar in de webapp en alleen-lezen in de mobiele app. Beschikbaarheid blijft apart.</p>
          </div>
        </div>
      </Panel>

      {canManageAgenda ? (
        <Panel title="Agenda-item toevoegen">
          <form className="form-grid" onSubmit={submit}>
            <label>
              Titel
              <input maxLength={180} value={form.title} onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))} required />
            </label>
            <label>
              Type
              <select value={form.type} onChange={(event) => setForm((current) => ({ ...current, type: event.target.value }))}>
                {eventTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
              </select>
            </label>
            <label>
              Start
              <input type="datetime-local" value={form.startsAt} onChange={(event) => setForm((current) => ({ ...current, startsAt: event.target.value }))} required />
            </label>
            <label>
              Einde
              <input type="datetime-local" value={form.endsAt} onChange={(event) => setForm((current) => ({ ...current, endsAt: event.target.value }))} />
            </label>
            <label>
              Locatie
              <input maxLength={255} value={form.locationLabel} onChange={(event) => setForm((current) => ({ ...current, locationLabel: event.target.value }))} />
            </label>
            <label>
              Team
              <select value={form.teamId} onChange={(event) => setForm((current) => ({ ...current, teamId: event.target.value }))}>
                <option value="">Iedereen</option>
                {teams.data?.map((team) => <option key={team.id} value={team.id}>{team.name}</option>)}
              </select>
            </label>
            <label className="form-grid__wide">
              Omschrijving
              <textarea rows={3} maxLength={2000} value={form.description} onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))} />
            </label>
            {error ? <p className="form-error form-grid__wide">{error}</p> : null}
            {message ? <p className="success-text form-grid__wide">{message}</p> : null}
            <div className="actions-row form-grid__wide">
              <button className="primary-button" type="submit" disabled={saving || form.title.trim() === '' || form.startsAt === ''}>
                <Plus size={16} /> {saving ? 'Opslaan...' : 'Toevoegen'}
              </button>
            </div>
          </form>
        </Panel>
      ) : null}

      <Panel title="Geplande items">
        <ResourceState loading={events.loading} error={events.error} empty={upcoming.length === 0}>
          <table className="data-table">
            <thead><tr><th>Datum</th><th>Type</th><th>Titel</th><th>Locatie</th><th>Team</th><th>Aangemaakt door</th>{canManageAgenda ? <th>Actie</th> : null}</tr></thead>
            <tbody>
              {upcoming.map((event) => (
                <tr key={event.id}>
                  <td>{formatDateTime(event.starts_at)}{event.ends_at ? <><br /><span>tot {formatDateTime(event.ends_at)}</span></> : null}</td>
                  <td>{eventTypeLabel(event.type)}</td>
                  <td><strong>{event.title}</strong>{event.description ? <><br /><span>{event.description}</span></> : null}</td>
                  <td>{event.location_label ?? '-'}</td>
                  <td>{event.team?.name ?? 'Iedereen'}</td>
                  <td>{event.created_by_name ?? '-'}</td>
                  {canManageAgenda ? <td><button className="secondary-button" type="button" onClick={() => void deleteEvent(event.id)}>Verwijderen</button></td> : null}
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>
    </div>
  );
}

function eventTypeLabel(value: CalendarEvent['type']): string {
  return eventTypes.find((type) => type.value === value)?.label ?? value;
}
