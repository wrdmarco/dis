import Link from 'next/link';
import {
  type FormEvent,
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import {
  CalendarCheck,
  CalendarDays,
  MapPin,
  Pencil,
  Plus,
  Settings2,
  Users,
  X,
} from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { CalendarAudienceGroup, CalendarEvent } from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  CalendarEventFields,
  calendarEventForm,
  calendarEventFormIsValid,
  calendarEventPayload,
  initialCalendarEventForm,
  type CalendarEventFormState,
} from './CalendarEventForm';
import { CalendarRegistrationDialog } from './CalendarRegistrationDialog';
import {
  calendarAudienceLabels,
  calendarEventTypeLabel,
  participantCountLabel,
  registrationStatusLabel,
  registrationStatusTone,
  remainingPlacesLabel,
} from './calendarPresentation';
import styles from './CalendarPage.module.css';

export function CalendarPage() {
  const { api, hasPermission } = useAuth();
  const canManageAgenda = hasPermission('calendar.manage');
  const canManageGroups = hasPermission('calendar.groups.manage');
  const events = useApiResource<CalendarEvent[]>('/calendar-events');
  const groupOptions = useApiResource<CalendarAudienceGroup[]>(
    '/calendar-events/group-options',
    canManageAgenda,
  );
  const upcoming = useMemo(
    () => [...(events.data ?? [])].sort(
      (left, right) => new Date(left.starts_at).getTime() - new Date(right.starts_at).getTime(),
    ),
    [events.data],
  );
  const [form, setForm] = useState<CalendarEventFormState>(initialCalendarEventForm);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [pendingRegistrationIds, setPendingRegistrationIds] = useState<Set<string>>(
    () => new Set(),
  );
  const [editingEvent, setEditingEvent] = useState<CalendarEvent | null>(null);
  const [editForm, setEditForm] = useState<CalendarEventFormState>(initialCalendarEventForm);
  const [editSaving, setEditSaving] = useState(false);
  const [editError, setEditError] = useState<string | null>(null);
  const [registrationEvent, setRegistrationEvent] = useState<CalendarEvent | null>(null);
  const defaultGroupInitializedRef = useRef(false);
  const editDialogRef = useRef<HTMLElement>(null);
  const editTitleInputRef = useRef<HTMLInputElement>(null);
  const editTriggerRef = useRef<HTMLButtonElement | null>(null);

  useEffect(() => {
    if (
      !canManageAgenda
      || groupOptions.data === null
      || defaultGroupInitializedRef.current
    ) {
      return;
    }

    defaultGroupInitializedRef.current = true;
    const everyoneGroup = groupOptions.data.find((group) => group.is_everyone);
    if (everyoneGroup !== undefined) {
      setForm((current) => current.groupIds.length === 0
        ? { ...current, groupIds: [everyoneGroup.id] }
        : current);
    }
  }, [canManageAgenda, groupOptions.data]);

  const closeEditModal = useCallback(() => {
    if (editSaving) {
      return;
    }
    setEditError(null);
    setEditingEvent(null);
  }, [editSaving]);

  useEffect(() => {
    if (editingEvent === null) {
      return undefined;
    }

    const trigger = editTriggerRef.current;
    const animationFrame = window.requestAnimationFrame(() => editTitleInputRef.current?.focus());
    const dialog = editDialogRef.current;
    function handleDialogKeyDown(keyEvent: KeyboardEvent) {
      if (keyEvent.key === 'Escape') {
        if (!editSaving) {
          keyEvent.preventDefault();
          closeEditModal();
        }
        return;
      }
      if (keyEvent.key !== 'Tab' || dialog === null) {
        return;
      }

      const focusable = Array.from(dialog.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
      )).filter((element) => element.tabIndex >= 0);
      const first = focusable[0];
      const last = focusable.at(-1);
      if (first === undefined || last === undefined) {
        keyEvent.preventDefault();
        dialog.focus();
      } else if (keyEvent.shiftKey && document.activeElement === first) {
        keyEvent.preventDefault();
        last.focus();
      } else if (!keyEvent.shiftKey && document.activeElement === last) {
        keyEvent.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', handleDialogKeyDown);
    return () => {
      window.cancelAnimationFrame(animationFrame);
      document.removeEventListener('keydown', handleDialogKeyDown);
      window.requestAnimationFrame(() => trigger?.focus());
    };
  }, [closeEditModal, editingEvent, editSaving]);

  async function submitCreate(submitEvent: FormEvent<HTMLFormElement>) {
    submitEvent.preventDefault();
    if (!calendarEventFormIsValid(form)) {
      return;
    }

    setSaving(true);
    clearFeedback();
    try {
      await api.post<CalendarEvent>('/calendar-events', calendarEventPayload(form));
      setForm(newCalendarEventForm(groupOptions.data));
      setMessage('Agenda-item toegevoegd.');
      await events.reload();
    } catch (err) {
      setError(apiError(err, 'Agenda-item opslaan mislukt.'));
    } finally {
      setSaving(false);
    }
  }

  function openEditModal(calendarEvent: CalendarEvent, trigger: HTMLButtonElement) {
    editTriggerRef.current = trigger;
    setEditForm(calendarEventForm(calendarEvent));
    setEditError(null);
    clearFeedback();
    setEditingEvent(calendarEvent);
  }

  async function submitEdit(submitEvent: FormEvent<HTMLFormElement>) {
    submitEvent.preventDefault();
    if (editingEvent === null || !calendarEventFormIsValid(editForm)) {
      return;
    }

    setEditSaving(true);
    setEditError(null);
    clearFeedback();
    try {
      const response = await api.patch<CalendarEvent>(
        `/calendar-events/${editingEvent.id}`,
        calendarEventPayload(editForm),
      );
      replaceEvent(response.data);
      setMessage('Agenda-item bijgewerkt.');
      setEditingEvent(null);
    } catch (err) {
      setEditError(apiError(err, 'Agenda-item aanpassen mislukt.'));
    } finally {
      setEditSaving(false);
    }
  }

  async function deleteEvent(eventId: string) {
    if (!window.confirm('Agenda-item verwijderen? Bestaande inschrijvingen blijven in de audit bewaard.')) {
      return;
    }

    clearFeedback();
    try {
      await api.delete(`/calendar-events/${eventId}`);
      setMessage('Agenda-item verwijderd.');
      await events.reload();
    } catch (err) {
      setError(apiError(err, 'Agenda-item verwijderen mislukt.'));
    }
  }

  async function updateOwnRegistration(calendarEvent: CalendarEvent, action: 'register' | 'unregister') {
    setPendingRegistrationIds((current) => new Set(current).add(calendarEvent.id));
    clearFeedback();
    try {
      const path = `/calendar-events/${calendarEvent.id}/registrations/me`;
      const response = action === 'register'
        ? await api.post<CalendarEvent>(path)
        : await api.delete<CalendarEvent>(path);
      replaceEvent(response.data);
      setMessage(action === 'register'
        ? `Je bent ingeschreven voor ${calendarEvent.title}.`
        : `Je bent afgemeld voor ${calendarEvent.title}.`);
    } catch (err) {
      const conflict = err instanceof ApiClientError && err.status === 409;
      const conflictMessage = err instanceof ApiClientError
        ? registrationConflictMessage(err)
        : null;
      setError(conflict && action === 'register' && conflictMessage !== null
        ? conflictMessage
        : apiError(err, action === 'register' ? 'Inschrijven mislukt.' : 'Afmelden mislukt.'));
      if (conflict) {
        await events.silentReload();
      }
    } finally {
      setPendingRegistrationIds((current) => {
        const next = new Set(current);
        next.delete(calendarEvent.id);
        return next;
      });
    }
  }

  function replaceEvent(updated: CalendarEvent) {
    events.mutate((current) => current?.map((item) => (
      item.id === updated.id ? updated : item
    )) ?? current);
    setRegistrationEvent((current) => current?.id === updated.id ? updated : current);
    setEditingEvent((current) => current?.id === updated.id ? updated : current);
  }

  function clearFeedback() {
    setMessage(null);
    setError(null);
  }

  return (
    <div className={`page-stack ${styles.calendarPage}`}>
      <Panel
        title="Agenda"
        action={canManageGroups ? (
          <Link className="secondary-button" href="/calendar/groups">
            <Settings2 size={16} aria-hidden /> Agendagroepen
          </Link>
        ) : undefined}
      >
        <div className={styles.hero}>
          <div className={styles.heroIcon}><CalendarDays size={28} aria-hidden /></div>
          <div>
            <h3>Trainingen, open dagen en teammomenten</h3>
            <p>
              Bekijk voor wie een moment bedoeld is, hoeveel plaatsen er zijn en schrijf je direct
              in via web of de Operator-app.
            </p>
          </div>
        </div>
        {message ? <p className="success-text" role="status">{message}</p> : null}
        {error ? <p className="form-error" role="alert">{error}</p> : null}
      </Panel>

      {canManageAgenda ? (
        <Panel title="Agenda-item toevoegen">
          <form className="form-grid" onSubmit={submitCreate}>
            <CalendarEventFields
              form={form}
              setForm={setForm}
              groups={groupOptions.data}
            />
            {groupOptions.error ? (
              <p className="form-error form-grid__wide">{groupOptions.error}</p>
            ) : null}
            <div className="actions-row form-grid__wide">
              <button
                className="primary-button"
                type="submit"
                disabled={saving || !calendarEventFormIsValid(form)}
              >
                <Plus size={16} aria-hidden /> {saving ? 'Opslaan...' : 'Toevoegen'}
              </button>
            </div>
          </form>
        </Panel>
      ) : null}

      <Panel title="Geplande items">
        <ResourceState loading={events.loading} error={events.error} empty={upcoming.length === 0}>
          <div className={styles.eventGrid}>
            {upcoming.map((calendarEvent) => (
              <CalendarEventCard
                key={calendarEvent.id}
                event={calendarEvent}
                canManageAgenda={canManageAgenda}
                registrationPending={pendingRegistrationIds.has(calendarEvent.id)}
                onRegister={() => void updateOwnRegistration(calendarEvent, 'register')}
                onUnregister={() => void updateOwnRegistration(calendarEvent, 'unregister')}
                onParticipants={() => setRegistrationEvent(calendarEvent)}
                onEdit={(trigger) => openEditModal(calendarEvent, trigger)}
                onDelete={() => void deleteEvent(calendarEvent.id)}
              />
            ))}
          </div>
        </ResourceState>
      </Panel>

      {editingEvent !== null && canManageAgenda ? (
        <div
          className="modal-backdrop"
          role="presentation"
          onMouseDown={(mouseEvent) => {
            if (mouseEvent.target === mouseEvent.currentTarget) {
              closeEditModal();
            }
          }}
        >
          <section
            ref={editDialogRef}
            className={`modal ${styles.editDialog}`}
            role="dialog"
            tabIndex={-1}
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
                <X size={18} aria-hidden />
              </button>
            </header>
            <form className={`form-grid ${styles.dialogForm}`} onSubmit={submitEdit}>
              <CalendarEventFields
                form={editForm}
                setForm={setEditForm}
                groups={groupOptions.data}
                titleInputRef={editTitleInputRef}
                participantCount={editingEvent.registration?.participant_count ?? 0}
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
                  disabled={editSaving || !calendarEventFormIsValid(editForm)}
                >
                  {editSaving ? 'Opslaan...' : 'Wijzigingen opslaan'}
                </button>
              </div>
            </form>
          </section>
        </div>
      ) : null}

      {registrationEvent !== null
        && registrationEvent.registration?.can_view_participants === true ? (
          <CalendarRegistrationDialog
            event={registrationEvent}
            onClose={() => setRegistrationEvent(null)}
            onEventUpdated={replaceEvent}
          />
        ) : null}
    </div>
  );
}

interface CalendarEventCardProps {
  event: CalendarEvent;
  canManageAgenda: boolean;
  registrationPending: boolean;
  onRegister: () => void;
  onUnregister: () => void;
  onParticipants: () => void;
  onEdit: (trigger: HTMLButtonElement) => void;
  onDelete: () => void;
}

function CalendarEventCard({
  event,
  canManageAgenda,
  registrationPending,
  onRegister,
  onUnregister,
  onParticipants,
  onEdit,
  onDelete,
}: CalendarEventCardProps) {
  const dateParts = calendarDateParts(event.starts_at);
  const registration = event.registration;
  const audienceLabels = calendarAudienceLabels(event);
  const canViewParticipants = registration?.can_view_participants === true;

  return (
    <article className={styles.eventCard}>
      <div className={styles.dateRail} aria-hidden>
        <span>{dateParts.month}</span>
        <strong>{dateParts.day}</strong>
        <small>{dateParts.weekday}</small>
      </div>
      <div className={styles.eventBody}>
        <header className={styles.eventHeader}>
          <div>
            <span className={styles.eventType}>{calendarEventTypeLabel(event.type)}</span>
            <h3>{event.title}</h3>
          </div>
          {registration ? (
            <StatusPill
              value={registrationStatusLabel(registration)}
              tone={registrationStatusTone(registration)}
            />
          ) : (
            <StatusPill value="Geen inschrijving" />
          )}
        </header>

        <p className={styles.eventTime}>
          <CalendarCheck size={18} aria-hidden />
          <span>
            {formatDateTime(event.starts_at)}
            {event.ends_at ? ` tot ${formatDateTime(event.ends_at)}` : ''}
          </span>
        </p>
        {event.location_label ? (
          <p className={styles.eventLocation}>
            <MapPin size={18} aria-hidden />
            <span>{event.location_label}</span>
          </p>
        ) : null}
        {event.description ? <p className={styles.eventDescription}>{event.description}</p> : null}

        <div className={styles.groupChips} aria-label="Doelgroep">
          {audienceLabels.map((label) => <span key={label}>{label}</span>)}
        </div>

        {registration ? (
          <div className={styles.registrationSummary}>
            <div>
              <Users size={20} aria-hidden />
              <span>
                <strong>{participantCountLabel(registration)}</strong>
                {remainingPlacesLabel(registration) ? (
                  <small>{remainingPlacesLabel(registration)}</small>
                ) : null}
              </span>
            </div>
            {registration.current_user_registered ? (
              <span className={styles.registeredBadge}>Je komt</span>
            ) : null}
          </div>
        ) : null}

        <div className={styles.cardActions}>
          {registration?.can_unregister ? (
            <button
              className="secondary-button"
              type="button"
              onClick={onUnregister}
              disabled={registrationPending}
            >
              {registrationPending ? 'Afmelden...' : 'Afmelden'}
            </button>
          ) : null}
          {registration?.can_register ? (
            <button
              className="primary-button"
              type="button"
              onClick={onRegister}
              disabled={registrationPending}
            >
              <CalendarCheck size={16} aria-hidden />
              {registrationPending ? 'Inschrijven...' : 'Ik kom'}
            </button>
          ) : null}
          {registration?.enabled
            && !registration.can_register
            && !registration.can_unregister
            && registration.status !== 'open' ? (
              <button className="secondary-button" type="button" disabled>
                {registration.status === 'full' ? 'Vol' : 'Gesloten'}
              </button>
            ) : null}
          {canViewParticipants ? (
            <button className="secondary-button" type="button" onClick={onParticipants}>
              <Users size={16} aria-hidden /> Deelnemers
            </button>
          ) : null}
          {canManageAgenda ? (
            <>
              <button
                className="secondary-button"
                type="button"
                onClick={(clickEvent) => onEdit(clickEvent.currentTarget)}
              >
                <Pencil size={16} aria-hidden /> Aanpassen
              </button>
              <button className="secondary-button" type="button" onClick={onDelete}>
                Verwijderen
              </button>
            </>
          ) : null}
        </div>

        {registration?.unavailable_reason
          && !registration.can_register
          && !registration.current_user_registered ? (
            <p className={styles.registrationReason}>
              {registrationUnavailableReason(registration.unavailable_reason)}
            </p>
          ) : null}
        {event.created_by_name ? (
          <footer className={styles.eventFooter}>Aangemaakt door {event.created_by_name}</footer>
        ) : null}
      </div>
    </article>
  );
}

function calendarDateParts(value: string): { month: string; day: string; weekday: string } {
  const date = new Date(value);
  return {
    month: new Intl.DateTimeFormat('nl-NL', { month: 'short', timeZone: 'Europe/Amsterdam' })
      .format(date)
      .replace('.', '')
      .toUpperCase(),
    day: new Intl.DateTimeFormat('nl-NL', { day: '2-digit', timeZone: 'Europe/Amsterdam' }).format(date),
    weekday: new Intl.DateTimeFormat('nl-NL', { weekday: 'short', timeZone: 'Europe/Amsterdam' })
      .format(date)
      .replace('.', ''),
  };
}

function registrationUnavailableReason(reason: string): string {
  const labels: Record<string, string> = {
    already_registered: 'Je bent al ingeschreven.',
    full: 'Dit agenda-item is vol.',
    calendar_event_full: 'Dit agenda-item is vol.',
    closed: 'De inschrijving is gesloten.',
    registration_closed: 'De inschrijving is gesloten.',
    not_eligible: 'Dit agenda-item is niet op jouw groep gericht.',
    outside_audience: 'Dit agenda-item is niet op jouw groep gericht.',
    permission_required: 'Je hebt geen recht om jezelf in te schrijven.',
    permission_missing: 'Je hebt geen recht om jezelf in te schrijven.',
    inactive_account: 'Je account is niet actief.',
  };
  return labels[reason] ?? reason;
}

function apiError(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}

function registrationConflictMessage(error: ApiClientError): string {
  if (error.code === 'calendar_event_full') {
    return 'Dit agenda-item is zojuist vol geraakt. De actuele status is opnieuw geladen.';
  }
  if (error.code === 'calendar_registration_closed') {
    return 'De inschrijving is zojuist gesloten. De actuele status is opnieuw geladen.';
  }

  return error.message;
}

function newCalendarEventForm(
  groups: CalendarAudienceGroup[] | null,
): CalendarEventFormState {
  const everyoneGroup = groups?.find((group) => group.is_everyone);
  return {
    ...initialCalendarEventForm,
    groupIds: everyoneGroup ? [everyoneGroup.id] : [],
  };
}
