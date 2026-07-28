import { type Dispatch, type FormEvent, type Ref, type SetStateAction, useEffect, useMemo, useRef, useState } from 'react';
import { CalendarDays, Pencil, Plus, X } from 'lucide-react';
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

interface CalendarEventFormState {
  title: string;
  type: CalendarEvent['type'];
  startsAt: string;
  endsAt: string;
  locationLabel: string;
  description: string;
  teamId: string;
}

const initialForm: CalendarEventFormState = {
  title: '',
  type: 'training',
  startsAt: '',
  endsAt: '',
  locationLabel: '',
  description: '',
  teamId: '',
};

const calendarDateTimePattern = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/;

export function CalendarPage() {
  const { api, hasPermission } = useAuth();
  const canManageAgenda = hasPermission('calendar.manage');
  const events = useApiResource<CalendarEvent[]>('/calendar-events');
  const teams = useApiResource<Team[]>('/calendar-events/team-options', canManageAgenda);
  const upcoming = useMemo(() => [...(events.data ?? [])].sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime()), [events.data]);
  const [form, setForm] = useState(initialForm);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editingEvent, setEditingEvent] = useState<CalendarEvent | null>(null);
  const [editForm, setEditForm] = useState<CalendarEventFormState>(initialForm);
  const [editSaving, setEditSaving] = useState(false);
  const [editError, setEditError] = useState<string | null>(null);
  const editDialogRef = useRef<HTMLElement>(null);
  const editTitleInputRef = useRef<HTMLInputElement>(null);
  const editTriggerRef = useRef<HTMLButtonElement | null>(null);

  useEffect(() => {
    if (editingEvent === null) {
      return undefined;
    }

    const trigger = editTriggerRef.current;
    const animationFrame = window.requestAnimationFrame(() => editTitleInputRef.current?.focus());

    return () => {
      window.cancelAnimationFrame(animationFrame);
      window.requestAnimationFrame(() => trigger?.focus());
    };
  }, [editingEvent]);

  useEffect(() => {
    if (editingEvent === null) {
      return undefined;
    }

    function handleDialogKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        if (!editSaving) {
          event.preventDefault();
          setEditError(null);
          setEditingEvent(null);
        }

        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      const focusableElements = Array.from(editDialogRef.current?.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
      ) ?? []);
      const firstElement = focusableElements[0];
      const lastElement = focusableElements.at(-1);

      if (firstElement === undefined || lastElement === undefined) {
        return;
      }

      if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault();
        lastElement.focus();
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault();
        firstElement.focus();
      }
    }

    document.addEventListener('keydown', handleDialogKeyDown);

    return () => document.removeEventListener('keydown', handleDialogKeyDown);
  }, [editingEvent, editSaving]);

  async function submitCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setMessage(null);
    setError(null);

    try {
      await api.post<CalendarEvent>('/calendar-events', calendarEventPayload(form));
      setForm(initialForm);
      setMessage('Agenda-item toegevoegd.');
      await events.reload();
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Agenda-item opslaan mislukt.');
    } finally {
      setSaving(false);
    }
  }

  function openEditModal(calendarEvent: CalendarEvent, trigger: HTMLButtonElement) {
    editTriggerRef.current = trigger;
    setEditForm(calendarEventForm(calendarEvent));
    setEditError(null);
    setMessage(null);
    setError(null);
    setEditingEvent(calendarEvent);
  }

  function closeEditModal() {
    if (editSaving) {
      return;
    }

    setEditError(null);
    setEditingEvent(null);
  }

  async function submitEdit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (editingEvent === null) {
      return;
    }

    setEditSaving(true);
    setEditError(null);
    setMessage(null);
    setError(null);

    try {
      const response = await api.patch<CalendarEvent>(
        `/calendar-events/${editingEvent.id}`,
        calendarEventPayload(editForm),
      );
      events.mutate((current) => current?.map((item) => (
        item.id === response.data.id ? response.data : item
      )) ?? current);
      setMessage('Agenda-item bijgewerkt.');
      setEditingEvent(null);
    } catch (err) {
      setEditError(err instanceof ApiClientError ? err.message : 'Agenda-item aanpassen mislukt.');
    } finally {
      setEditSaving(false);
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
          <form className="form-grid" onSubmit={submitCreate}>
            <CalendarEventFields form={form} setForm={setForm} teams={teams.data} />
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
            <thead><tr><th scope="col">Datum</th><th scope="col">Type</th><th scope="col">Titel</th><th scope="col">Locatie</th><th scope="col">Team</th><th scope="col">Aangemaakt door</th>{canManageAgenda ? <th scope="col">Acties</th> : null}</tr></thead>
            <tbody>
              {upcoming.map((event) => (
                <tr key={event.id}>
                  <td>{formatDateTime(event.starts_at)}{event.ends_at ? <><br /><span>tot {formatDateTime(event.ends_at)}</span></> : null}</td>
                  <td>{eventTypeLabel(event.type)}</td>
                  <td><strong>{event.title}</strong>{event.description ? <><br /><span>{event.description}</span></> : null}</td>
                  <td>{event.location_label ?? '-'}</td>
                  <td>{event.team?.name ?? 'Iedereen'}</td>
                  <td>{event.created_by_name ?? '-'}</td>
                  {canManageAgenda ? (
                    <td>
                      <div className="table-actions">
                        <button
                          className="secondary-button"
                          type="button"
                          onClick={(clickEvent) => openEditModal(event, clickEvent.currentTarget)}
                        >
                          <Pencil size={16} aria-hidden="true" /> Aanpassen
                        </button>
                        <button className="secondary-button" type="button" onClick={() => void deleteEvent(event.id)}>Verwijderen</button>
                      </div>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </ResourceState>
      </Panel>

      {editingEvent !== null && canManageAgenda ? (
        <div className="modal-backdrop" role="presentation">
          <section
            ref={editDialogRef}
            className="modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="calendar-edit-title"
            aria-describedby={editError ? 'calendar-edit-error' : undefined}
          >
            <header className="modal__header">
              <h2 id="calendar-edit-title">Agenda-item aanpassen</h2>
              <button
                className="icon-button"
                type="button"
                onClick={closeEditModal}
                disabled={editSaving}
                aria-label="Bewerkvenster sluiten"
              >
                <X size={18} aria-hidden="true" />
              </button>
            </header>
            <form className="form-grid" onSubmit={submitEdit}>
              <CalendarEventFields
                form={editForm}
                setForm={setEditForm}
                teams={teams.data}
                titleInputRef={editTitleInputRef}
              />
              {editError ? (
                <p id="calendar-edit-error" className="form-error form-grid__wide" role="alert">
                  {editError}
                </p>
              ) : null}
              <div className="actions-row form-grid__wide">
                <button className="secondary-button" type="button" onClick={closeEditModal} disabled={editSaving}>
                  Annuleren
                </button>
                <button
                  className="primary-button"
                  type="submit"
                  disabled={editSaving || editForm.title.trim() === '' || editForm.startsAt === ''}
                >
                  {editSaving ? 'Opslaan...' : 'Wijzigingen opslaan'}
                </button>
              </div>
            </form>
          </section>
        </div>
      ) : null}
    </div>
  );
}

interface CalendarEventFieldsProps {
  form: CalendarEventFormState;
  setForm: Dispatch<SetStateAction<CalendarEventFormState>>;
  teams: Team[] | null;
  titleInputRef?: Ref<HTMLInputElement>;
}

function CalendarEventFields({ form, setForm, teams, titleInputRef }: CalendarEventFieldsProps) {
  return (
    <>
      <label>
        Titel
        <input
          ref={titleInputRef}
          maxLength={180}
          value={form.title}
          onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))}
          required
        />
      </label>
      <label>
        Type
        <select
          value={form.type}
          onChange={(event) => setForm((current) => ({ ...current, type: event.target.value as CalendarEvent['type'] }))}
        >
          {eventTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
        </select>
      </label>
      <label>
        Start
        <input
          type="datetime-local"
          value={form.startsAt}
          onChange={(event) => setForm((current) => ({ ...current, startsAt: event.target.value }))}
          required
        />
      </label>
      <label>
        Einde
        <input
          type="datetime-local"
          value={form.endsAt}
          min={form.startsAt || undefined}
          onChange={(event) => setForm((current) => ({ ...current, endsAt: event.target.value }))}
        />
      </label>
      <label>
        Locatie
        <input
          maxLength={255}
          value={form.locationLabel}
          onChange={(event) => setForm((current) => ({ ...current, locationLabel: event.target.value }))}
        />
      </label>
      <label>
        Team
        <select
          value={form.teamId}
          onChange={(event) => setForm((current) => ({ ...current, teamId: event.target.value }))}
        >
          <option value="">Iedereen</option>
          {teams?.map((team) => <option key={team.id} value={team.id}>{team.name}</option>)}
        </select>
      </label>
      <label className="form-grid__wide">
        Omschrijving
        <textarea
          rows={3}
          maxLength={2000}
          value={form.description}
          onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
        />
      </label>
    </>
  );
}

function calendarEventForm(event: CalendarEvent): CalendarEventFormState {
  return {
    title: event.title,
    type: event.type,
    startsAt: calendarDateTimeLocalValue(event.starts_at),
    endsAt: calendarDateTimeLocalValue(event.ends_at),
    locationLabel: event.location_label ?? '',
    description: event.description ?? '',
    teamId: event.team_id ?? event.team?.id ?? '',
  };
}

function calendarDateTimeLocalValue(value: string | null | undefined): string {
  if (!value) {
    return '';
  }

  const match = value.match(calendarDateTimePattern);

  return match ? `${match[1]}T${match[2]}` : '';
}

function calendarEventPayload(form: CalendarEventFormState) {
  return {
    title: form.title.trim(),
    type: form.type,
    starts_at: form.startsAt,
    ends_at: form.endsAt || null,
    location_label: form.locationLabel.trim() || null,
    description: form.description.trim() || null,
    team_id: form.teamId || null,
  };
}

function eventTypeLabel(value: CalendarEvent['type']): string {
  return eventTypes.find((type) => type.value === value)?.label ?? value;
}
