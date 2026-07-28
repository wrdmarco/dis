import type { Dispatch, Ref, SetStateAction } from 'react';
import type { CalendarAudienceGroup, CalendarEvent } from '../../types/api';
import { calendarEventTypes } from './calendarPresentation';
import styles from './CalendarPage.module.css';

export interface CalendarEventFormState {
  title: string;
  type: CalendarEvent['type'];
  startsAt: string;
  endsAt: string;
  locationLabel: string;
  description: string;
  groupIds: string[];
  registrationEnabled: boolean;
  maxParticipants: string;
}

export const initialCalendarEventForm: CalendarEventFormState = {
  title: '',
  type: 'training',
  startsAt: '',
  endsAt: '',
  locationLabel: '',
  description: '',
  groupIds: [],
  registrationEnabled: false,
  maxParticipants: '',
};

const calendarDateTimePattern = /^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/;

interface CalendarEventFieldsProps {
  form: CalendarEventFormState;
  setForm: Dispatch<SetStateAction<CalendarEventFormState>>;
  groups: CalendarAudienceGroup[] | null;
  titleInputRef?: Ref<HTMLInputElement>;
  participantCount?: number;
}

export function CalendarEventFields({
  form,
  setForm,
  groups,
  titleInputRef,
  participantCount = 0,
}: CalendarEventFieldsProps) {
  const selectedGroupIds = new Set(form.groupIds);
  const hasSpecificGroupSelection = groups?.some(
    (group) => !group.is_everyone && selectedGroupIds.has(group.id),
  ) ?? false;

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
          onChange={(event) => setForm((current) => ({
            ...current,
            type: event.target.value as CalendarEvent['type'],
          }))}
        >
          {calendarEventTypes.map((type) => (
            <option key={type.value} value={type.value}>{type.label}</option>
          ))}
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
      <fieldset className={`form-grid__wide ${styles.groupFieldset}`}>
        <legend>Agendagroepen</legend>
        <p className={styles.fieldsetIntro}>
          Kies Iedereen óf één of meer specifieke groepen. Een groep kan individuele gebruikers
          en actuele leden van gekoppelde teams bevatten.
        </p>
        {groups === null ? <p className="form-note">Groepen laden...</p> : null}
        {groups?.length === 0 ? (
          <p className="form-note">Maak eerst een agendagroep aan.</p>
        ) : null}
        <div className={styles.groupChoices}>
          {groups?.map((group) => {
            const checked = selectedGroupIds.has(group.id);
            const disabled = group.is_everyone && hasSpecificGroupSelection;
            return (
              <label className={styles.groupChoice} key={group.id}>
                <input
                  type="checkbox"
                  checked={checked}
                  disabled={disabled}
                  onChange={(event) => {
                    const shouldSelect = event.currentTarget.checked;
                    setForm((current) => ({
                      ...current,
                      groupIds: nextCalendarGroupIds(
                        current.groupIds,
                        group,
                        shouldSelect,
                        groups,
                      ),
                    }));
                  }}
                />
                <span>
                  <strong>
                    {group.name}
                    {group.is_everyone ? <em>Systeemgroep</em> : null}
                  </strong>
                  <small>
                    {group.is_everyone
                      ? disabled
                        ? 'Niet beschikbaar zolang een specifieke groep is geselecteerd.'
                        : 'Bevat automatisch alle gebruikers.'
                      : `${group.effective_member_count ?? 0} effectieve leden`}
                  </small>
                </span>
              </label>
            );
          })}
        </div>
        {form.groupIds.length === 0 ? (
          <p className="form-error">Selecteer minimaal één agendagroep.</p>
        ) : null}
      </fieldset>

      <fieldset className={`form-grid__wide ${styles.registrationFieldset}`}>
        <legend>Inschrijving</legend>
        <label className={styles.switchChoice}>
          <input
            type="checkbox"
            checked={form.registrationEnabled}
            onChange={(event) => setForm((current) => ({
              ...current,
              registrationEnabled: event.target.checked,
              maxParticipants: event.target.checked ? current.maxParticipants : '',
            }))}
          />
          <span>
            <strong>Deelnemers kunnen zich inschrijven</strong>
            <small>Zet dit uit voor een informatief agenda-item zonder deelname.</small>
          </span>
        </label>
        {form.registrationEnabled ? (
          <label className={styles.capacityField}>
            Maximum deelnemers
            <input
              type="number"
              min={Math.max(1, participantCount)}
              max={100000}
              inputMode="numeric"
              value={form.maxParticipants}
              onChange={(event) => setForm((current) => ({
                ...current,
                maxParticipants: event.target.value,
              }))}
              placeholder="Geen maximum"
            />
            <small>
              Leeg betekent onbeperkt.
              {participantCount > 0 ? ` Er zijn nu ${participantCount} deelnemers.` : ''}
            </small>
          </label>
        ) : null}
      </fieldset>

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

export function calendarEventForm(event: CalendarEvent): CalendarEventFormState {
  const groupIds = event.group_ids ?? event.audience_groups?.map((group) => group.id) ?? [];
  const everyoneGroupIds = new Set(
    event.audience_groups
      ?.filter((group) => group.is_everyone)
      .map((group) => group.id) ?? [],
  );
  const specificGroupIds = groupIds.filter((groupId) => !everyoneGroupIds.has(groupId));

  return {
    title: event.title,
    type: event.type,
    startsAt: calendarDateTimeLocalValue(event.starts_at),
    endsAt: calendarDateTimeLocalValue(event.ends_at),
    locationLabel: event.location_label ?? '',
    description: event.description ?? '',
    groupIds: specificGroupIds.length > 0 ? specificGroupIds : groupIds,
    registrationEnabled: event.registration?.enabled ?? false,
    maxParticipants: event.registration?.max_participants?.toString() ?? '',
  };
}

export function calendarEventPayload(form: CalendarEventFormState) {
  return {
    title: form.title.trim(),
    type: form.type,
    starts_at: form.startsAt,
    ends_at: form.endsAt || null,
    location_label: form.locationLabel.trim() || null,
    description: form.description.trim() || null,
    group_ids: form.groupIds,
    registration_enabled: form.registrationEnabled,
    max_participants: form.registrationEnabled && form.maxParticipants !== ''
      ? Number(form.maxParticipants)
      : null,
  };
}

export function calendarEventFormIsValid(form: CalendarEventFormState): boolean {
  if (form.title.trim() === '' || form.startsAt === '') {
    return false;
  }
  if (form.groupIds.length === 0) {
    return false;
  }
  if (form.maxParticipants !== '') {
    const capacity = Number(form.maxParticipants);
    if (!Number.isInteger(capacity) || capacity < 1) {
      return false;
    }
  }

  return true;
}

function calendarDateTimeLocalValue(value: string | null | undefined): string {
  if (!value) {
    return '';
  }

  const match = value.match(calendarDateTimePattern);
  return match ? `${match[1]}T${match[2]}` : '';
}

function nextCalendarGroupIds(
  currentGroupIds: string[],
  toggledGroup: CalendarAudienceGroup,
  shouldSelect: boolean,
  groups: CalendarAudienceGroup[],
): string[] {
  if (toggledGroup.is_everyone) {
    return shouldSelect ? [toggledGroup.id] : currentGroupIds;
  }

  const everyoneGroupId = groups.find((group) => group.is_everyone)?.id;
  const specificGroupIds = currentGroupIds.filter((groupId) => groupId !== everyoneGroupId);
  const nextSpecificGroupIds = shouldSelect
    ? [...new Set([...specificGroupIds, toggledGroup.id])]
    : specificGroupIds.filter((groupId) => groupId !== toggledGroup.id);

  if (nextSpecificGroupIds.length > 0) {
    return nextSpecificGroupIds;
  }

  return everyoneGroupId === undefined ? [] : [everyoneGroupId];
}
