import type { CalendarEvent, CalendarRegistrationSummary } from '../../types/api';

export const calendarEventTypes = [
  { value: 'training', label: 'Training' },
  { value: 'open_day', label: 'Open dag' },
  { value: 'exercise', label: 'Oefening' },
  { value: 'meeting', label: 'Overleg' },
  { value: 'other', label: 'Overig' },
] as const;

export function calendarEventTypeLabel(value: CalendarEvent['type']): string {
  return calendarEventTypes.find((type) => type.value === value)?.label ?? value;
}

export function calendarAudienceLabels(event: CalendarEvent): string[] {
  const groupNames = event.audience_groups
    ?.map((group) => group.name.trim())
    .filter((name) => name !== '') ?? [];
  if (groupNames.length > 0) {
    return groupNames;
  }

  const legacyTeamName = event.team?.name.trim();
  return legacyTeamName ? [legacyTeamName] : ['Iedereen'];
}

export function registrationStatusLabel(registration: CalendarRegistrationSummary): string {
  if (!registration.enabled || registration.status === 'closed') {
    return 'Inschrijving gesloten';
  }
  if (registration.status === 'full') {
    return 'Vol – inschrijving gesloten';
  }

  return 'Inschrijving open';
}

export function registrationStatusTone(
  registration: CalendarRegistrationSummary,
): 'neutral' | 'good' | 'warn' | 'bad' {
  if (!registration.enabled || registration.status === 'closed') {
    return 'neutral';
  }
  if (registration.status === 'full') {
    return 'warn';
  }

  return 'good';
}

export function participantCountLabel(registration: CalendarRegistrationSummary): string {
  if (registration.max_participants === null) {
    return `${registration.participant_count} ${registration.participant_count === 1 ? 'deelnemer' : 'deelnemers'} · geen maximum`;
  }

  return `${registration.participant_count} van ${registration.max_participants} deelnemers`;
}

export function remainingPlacesLabel(registration: CalendarRegistrationSummary): string | null {
  if (!registration.enabled || registration.max_participants === null) {
    return null;
  }

  const remaining = Math.max(0, registration.max_participants - registration.participant_count);
  if (remaining === 0) {
    return 'Geen plaatsen meer beschikbaar';
  }

  return `${remaining} ${remaining === 1 ? 'plaats' : 'plaatsen'} vrij`;
}
