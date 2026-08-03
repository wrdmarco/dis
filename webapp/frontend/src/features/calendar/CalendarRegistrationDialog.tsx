import { type FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { TriangleAlert, UserPlus, Users, X } from 'lucide-react';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import type {
  CalendarEvent,
  CalendarRegistration,
  CalendarRegistrationOption,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import { participantCountLabel, remainingPlacesLabel } from './calendarPresentation';
import styles from './CalendarPage.module.css';

interface CalendarRegistrationDialogProps {
  event: CalendarEvent;
  onClose: () => void;
  onEventUpdated: (event: CalendarEvent) => void;
}

export function CalendarRegistrationDialog({
  event,
  onClose,
  onEventUpdated,
}: CalendarRegistrationDialogProps) {
  const { api } = useAuth();
  const dialogRef = useRef<HTMLElement>(null);
  const searchInputRef = useRef<HTMLInputElement>(null);
  const cancelRemovalButtonRef = useRef<HTMLButtonElement>(null);
  const removalTriggerUserIdRef = useRef<string | null>(null);
  const restoreRemovalFocusRef = useRef(false);
  const [participants, setParticipants] = useState<CalendarRegistration[]>([]);
  const [options, setOptions] = useState<CalendarRegistrationOption[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [searching, setSearching] = useState(false);
  const [pendingUserId, setPendingUserId] = useState<string | null>(null);
  const [participantToRemove, setParticipantToRemove] = useState<CalendarRegistration | null>(null);
  const [error, setError] = useState<string | null>(null);
  const registration = event.registration;
  const canManage = registration?.can_manage_participants === true;
  const cancelParticipantRemoval = useCallback(() => {
    restoreRemovalFocusRef.current = true;
    setParticipantToRemove(null);
  }, []);

  useEffect(() => {
    let active = true;

    async function loadInitial() {
      setLoading(true);
      setError(null);
      try {
        const [participantResponse, optionResponse] = await Promise.all([
          api.get<CalendarRegistration[]>(`/calendar-events/${event.id}/registrations`),
          canManage
            ? api.get<CalendarRegistrationOption[]>(`/calendar-events/${event.id}/registration-options`)
            : Promise.resolve({ data: [] as CalendarRegistrationOption[] }),
        ]);
        if (active) {
          setParticipants(participantResponse.data);
          setOptions(optionResponse.data);
        }
      } catch (err) {
        if (active) {
          setError(errorMessage(err, 'Deelnemers laden mislukt.'));
        }
      } finally {
        if (active) {
          setLoading(false);
        }
      }
    }

    void loadInitial();
    return () => {
      active = false;
    };
  }, [api, canManage, event.id]);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (dialog === null) {
      return undefined;
    }
    const currentDialog = dialog;

    const animationFrame = window.requestAnimationFrame(() => {
      if (participantToRemove !== null) {
        if (pendingUserId !== null) {
          currentDialog.focus();
        } else {
          cancelRemovalButtonRef.current?.focus();
        }
        return;
      }

      if (restoreRemovalFocusRef.current) {
        restoreRemovalFocusRef.current = false;
        const triggerUserId = removalTriggerUserIdRef.current;
        removalTriggerUserIdRef.current = null;
        const trigger = Array.from(currentDialog.querySelectorAll<HTMLButtonElement>(
          '[data-calendar-removal-trigger]',
        )).find((candidate) => candidate.dataset.calendarRemovalTrigger === triggerUserId);
        if (trigger !== undefined && !trigger.disabled) {
          trigger.focus();
          return;
        }
      }

      (canManage ? searchInputRef.current : currentDialog)?.focus();
    });
    function handleKeyDown(keyEvent: KeyboardEvent) {
      if (keyEvent.key === 'Escape' && pendingUserId === null) {
        keyEvent.preventDefault();
        if (participantToRemove !== null) {
          cancelParticipantRemoval();
        } else {
          onClose();
        }
        return;
      }
      if (keyEvent.key !== 'Tab') {
        return;
      }

      const focusable = Array.from(currentDialog.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
      )).filter((element) => element.tabIndex >= 0);
      const first = focusable[0];
      const last = focusable.at(-1);
      if (first === undefined || last === undefined) {
        keyEvent.preventDefault();
        currentDialog.focus();
      } else if (keyEvent.shiftKey && document.activeElement === first) {
        keyEvent.preventDefault();
        last.focus();
      } else if (!keyEvent.shiftKey && document.activeElement === last) {
        keyEvent.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      window.cancelAnimationFrame(animationFrame);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [canManage, cancelParticipantRemoval, onClose, participantToRemove, pendingUserId]);

  async function searchUsers(searchEvent: FormEvent<HTMLFormElement>) {
    searchEvent.preventDefault();
    if (!canManage) {
      return;
    }

    setSearching(true);
    setError(null);
    try {
      const query = search.trim() === '' ? '' : `?search=${encodeURIComponent(search.trim())}`;
      const response = await api.get<CalendarRegistrationOption[]>(
        `/calendar-events/${event.id}/registration-options${query}`,
      );
      setOptions(response.data);
    } catch (err) {
      setError(errorMessage(err, 'Gebruikers zoeken mislukt.'));
    } finally {
      setSearching(false);
    }
  }

  async function addParticipant(userId: string) {
    setPendingUserId(userId);
    setError(null);
    try {
      const response = await api.post<CalendarEvent>(
        `/calendar-events/${event.id}/registrations/${userId}`,
      );
      onEventUpdated(response.data);
      await reloadLists();
    } catch (err) {
      setError(errorMessage(err, 'Gebruiker inschrijven mislukt.'));
      if (err instanceof ApiClientError && err.status === 409) {
        await refreshEventAfterConflict();
      }
    } finally {
      setPendingUserId(null);
    }
  }

  async function removeParticipant(participant: CalendarRegistration) {
    setPendingUserId(participant.user.id);
    setError(null);
    try {
      const response = await api.delete<CalendarEvent>(
        `/calendar-events/${event.id}/registrations/${participant.user.id}`,
      );
      onEventUpdated(response.data);
      await reloadLists();
      setParticipantToRemove(null);
    } catch (err) {
      setError(errorMessage(err, 'Gebruiker uitschrijven mislukt.'));
    } finally {
      setPendingUserId(null);
    }
  }

  async function reloadLists() {
    const [participantResponse, optionResponse] = await Promise.all([
      api.get<CalendarRegistration[]>(`/calendar-events/${event.id}/registrations`),
      canManage
        ? api.get<CalendarRegistrationOption[]>(`/calendar-events/${event.id}/registration-options`)
        : Promise.resolve({ data: [] as CalendarRegistrationOption[] }),
    ]);
    setParticipants(participantResponse.data);
    setOptions(optionResponse.data);
  }

  async function refreshEventAfterConflict() {
    try {
      const response = await api.get<CalendarEvent[]>('/calendar-events');
      const updatedEvent = response.data.find((candidate) => candidate.id === event.id);
      if (updatedEvent !== undefined) {
        onEventUpdated(updatedEvent);
      }
    } catch {
      // Keep the original server conflict visible; the page refreshes on its normal next load.
    }
  }

  return (
    <div
      className="modal-backdrop"
      role="presentation"
      onMouseDown={(mouseEvent) => {
        if (mouseEvent.target === mouseEvent.currentTarget && pendingUserId === null) {
          if (participantToRemove !== null) {
            cancelParticipantRemoval();
          } else {
            onClose();
          }
        }
      }}
    >
      <section
        ref={dialogRef}
        className={`modal ${styles.registrationDialog}`}
        role={participantToRemove !== null ? 'alertdialog' : 'dialog'}
        tabIndex={-1}
        aria-modal="true"
        aria-busy={pendingUserId !== null}
        aria-labelledby={participantToRemove !== null
          ? 'calendar-registration-confirm-title'
          : 'calendar-registration-title'}
        aria-describedby={participantToRemove !== null
          ? 'calendar-registration-confirm-description'
          : 'calendar-registration-description'}
      >
        <header className="modal__header">
          <div>
            <span className={styles.dialogEyebrow}>Deelnemers</span>
            <h2 id="calendar-registration-title">{event.title}</h2>
          </div>
          <button
            className="icon-button"
            type="button"
            onClick={() => {
              if (participantToRemove !== null) {
                cancelParticipantRemoval();
              } else {
                onClose();
              }
            }}
            disabled={pendingUserId !== null}
            aria-label={participantToRemove !== null ? 'Bevestiging sluiten' : 'Deelnemersvenster sluiten'}
          >
            <X size={18} aria-hidden />
          </button>
        </header>

        {participantToRemove !== null ? (
          <div className={styles.registrationConfirmation}>
            <span className={styles.registrationConfirmationIcon} aria-hidden="true">
              <TriangleAlert size={24} />
            </span>
            <div className={styles.registrationConfirmationCopy}>
              <span className={styles.dialogEyebrow}>Bevestiging</span>
              <h3 id="calendar-registration-confirm-title">Deelnemer uitschrijven?</h3>
              <p id="calendar-registration-confirm-description">
                Je schrijft {participantToRemove.user.name} uit voor “{event.title}”.
              </p>
            </div>
            {error ? <p className="form-error" role="alert">{error}</p> : null}
            <div className={styles.registrationConfirmationActions}>
              <button
                ref={cancelRemovalButtonRef}
                className="secondary-button"
                type="button"
                onClick={cancelParticipantRemoval}
                disabled={pendingUserId !== null}
              >
                Annuleren
              </button>
              <button
                className="danger-button"
                type="button"
                onClick={() => void removeParticipant(participantToRemove)}
                disabled={pendingUserId !== null}
              >
                {pendingUserId === participantToRemove.user.id ? 'Uitschrijven...' : 'Deelnemer uitschrijven'}
              </button>
            </div>
          </div>
        ) : (
          <div className={styles.dialogBody}>
            <div id="calendar-registration-description" className={styles.capacitySummary}>
              <Users size={20} aria-hidden />
              <div>
                <strong>{registration ? participantCountLabel(registration) : 'Deelnemers'}</strong>
                {registration ? <span>{remainingPlacesLabel(registration) ?? 'Onbeperkte capaciteit'}</span> : null}
              </div>
            </div>

          {canManage ? (
            <form className={styles.participantSearch} onSubmit={searchUsers}>
              <label htmlFor="calendar-participant-search">Gebruiker inschrijven</label>
              <div>
                <input
                  ref={searchInputRef}
                  id="calendar-participant-search"
                  type="search"
                  value={search}
                  onChange={(searchEvent) => setSearch(searchEvent.target.value)}
                  placeholder="Zoek op naam of e-mailadres"
                />
                <button className="secondary-button" type="submit" disabled={searching}>
                  {searching ? 'Zoeken...' : 'Zoeken'}
                </button>
              </div>
              {options.length > 0 ? (
                <ul className={styles.registrationOptions} aria-label="Inschrijfbare gebruikers">
                  {options.map((option) => (
                    <li key={option.id}>
                      <span>
                        <strong>{option.name}</strong>
                        <small>{option.email}</small>
                      </span>
                      <button
                        className="secondary-button"
                        type="button"
                        onClick={() => void addParticipant(option.id)}
                        disabled={pendingUserId !== null || registration?.status !== 'open'}
                      >
                        <UserPlus size={16} aria-hidden />
                        {pendingUserId === option.id ? 'Inschrijven...' : 'Inschrijven'}
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="form-note">Geen beschikbare doelgroepgebruikers gevonden.</p>
              )}
            </form>
          ) : null}

          {error ? <p className="form-error" role="alert">{error}</p> : null}

          <section aria-labelledby="calendar-current-participants">
            <h3 id="calendar-current-participants">Huidige deelnemers</h3>
            {loading ? <p className="form-note">Deelnemers laden...</p> : null}
            {!loading && participants.length === 0 ? (
              <p className="form-note">Er zijn nog geen deelnemers ingeschreven.</p>
            ) : null}
            <ul className={styles.participantList}>
              {participants.map((participant) => (
                <li key={participant.id}>
                  <span>
                    <strong>{participant.user.name}</strong>
                    <small>{participant.user.email}</small>
                    <small>
                      Ingeschreven {formatDateTime(participant.registered_at)}
                      {participant.registered_by_name ? ` door ${participant.registered_by_name}` : ''}
                    </small>
                  </span>
                  {canManage ? (
                    <button
                      className="secondary-button"
                      type="button"
                      data-calendar-removal-trigger={participant.user.id}
                      onClick={(clickEvent) => {
                        removalTriggerUserIdRef.current = clickEvent.currentTarget.dataset.calendarRemovalTrigger
                          ?? null;
                        setError(null);
                        setParticipantToRemove(participant);
                      }}
                      disabled={pendingUserId !== null}
                    >
                      {pendingUserId === participant.user.id ? 'Uitschrijven...' : 'Uitschrijven'}
                    </button>
                  ) : null}
                </li>
              ))}
            </ul>
          </section>
          </div>
        )}
      </section>
    </div>
  );
}

function errorMessage(error: unknown, fallback: string): string {
  return error instanceof ApiClientError ? error.message : fallback;
}
