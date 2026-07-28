'use client';

import { useEffect, useId, useRef, useState } from 'react';
import { Pencil, X } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateOnly, todayAmsterdamDateInputValue } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { AvailabilityOverride, AvailabilitySchedule, AvailabilityScheduleDay } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

type AvailabilityDayPart = 'morning' | 'afternoon' | 'evening';
type AvailabilityScheduleScope = 'mine' | 'user';

const dayParts: AvailabilityDayPart[] = ['morning', 'afternoon', 'evening'];
const daysOfWeek = [1, 2, 3, 4, 5, 6, 7];

interface UserAvailabilityScheduleProps {
  userId?: string;
  canView: boolean;
  canManage?: boolean;
  refreshVersion?: number;
  scope?: AvailabilityScheduleScope;
}

export function UserAvailabilitySchedule({
  userId,
  canView,
  canManage = false,
  refreshVersion = 0,
  scope = 'user',
}: UserAvailabilityScheduleProps) {
  const { api } = useAuth();
  const editorTitleId = useId();
  const enabled = canView && (scope === 'mine' || userId !== undefined);
  const basePath = scope === 'mine'
    ? '/availability-schedule/me'
    : `/availability-statuses/users/${encodeURIComponent(userId ?? '')}/availability-schedule`;
  const schedule = useApiResource<AvailabilitySchedule>(basePath, enabled);
  const silentReloadSchedule = schedule.silentReload;
  const previousRefreshVersion = useRef(refreshVersion);
  const [editorOpen, setEditorOpen] = useState(false);
  const [weekDraft, setWeekDraft] = useState<AvailabilityScheduleDay[] | null>(null);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  useEffect(() => {
    if (previousRefreshVersion.current === refreshVersion) {
      return;
    }

    previousRefreshVersion.current = refreshVersion;
    void silentReloadSchedule();
  }, [refreshVersion, silentReloadSchedule]);

  if (!enabled) {
    return null;
  }

  function openEditor() {
    if (!canManage || schedule.data === null) {
      return;
    }

    setWeekDraft(weekDayPartPattern(schedule.data));
    setMessage(null);
    setActionError(null);
    setEditorOpen(true);
  }

  function closeEditor() {
    if (saving) {
      return;
    }

    setEditorOpen(false);
    setWeekDraft(null);
    setMessage(null);
    setActionError(null);
  }

  function updateWeekDayPart(dayOfWeek: number, dayPart: AvailabilityDayPart, isAvailable: boolean) {
    setWeekDraft((current) => current?.map((day) => (
      day.day_of_week === dayOfWeek && day.day_part === dayPart
        ? { ...day, is_available: isAvailable }
        : day
    )) ?? null);
  }

  async function saveWeekPattern() {
    if (!canManage || weekDraft === null) {
      return;
    }

    setSaving(true);
    setMessage(null);
    setActionError(null);
    try {
      const response = await api.patch<AvailabilitySchedule>(`${basePath}/week-pattern`, {
        patterns: weekDraft.map((day) => ({
          day_of_week: day.day_of_week,
          day_part: day.day_part,
          is_available: day.is_available,
          note: day.note ?? null,
        })),
      });
      schedule.mutate(response.data);
      setWeekDraft(weekDayPartPattern(response.data));
      setMessage('Vaste weekplanning opgeslagen.');
    } catch (error) {
      setActionError(error instanceof ApiClientError
        ? error.message
        : 'De vaste weekplanning kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  async function planDayPart(date: string, dayPart: AvailabilityDayPart, isAvailable: boolean) {
    if (!canManage) {
      return;
    }

    setSaving(true);
    setMessage(null);
    setActionError(null);
    try {
      const response = await api.post<AvailabilitySchedule>(`${basePath}/overrides`, {
        starts_at: date,
        ends_at: date,
        day_part: dayPart,
        is_available: isAvailable,
        note: `Gepland via werkplanning: ${dayPartLabel(dayPart).toLowerCase()}`,
      });
      schedule.mutate(response.data);
      setMessage(
        `${shortDateLabel(date)} ${dayPartLabel(dayPart).toLowerCase()} is als `
        + `${isAvailable ? 'beschikbaar' : 'niet beschikbaar'} opgeslagen.`,
      );
    } catch (error) {
      setActionError(error instanceof ApiClientError
        ? error.message
        : 'Het dagdeel kon niet worden opgeslagen.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <Panel
        title={scope === 'mine' ? 'Mijn beschikbaarheid' : 'Wekelijkse planning'}
        action={canManage ? (
          <button
            className="primary-button"
            type="button"
            onClick={openEditor}
            disabled={schedule.data === null || schedule.loading}
          >
            <Pencil size={16} /> Planning aanpassen
          </button>
        ) : undefined}
      >
        <ResourceState loading={schedule.loading} error={schedule.error} empty={schedule.data === null}>
          {schedule.data !== null ? (
            <AvailabilityScheduleOverview schedule={schedule.data} />
          ) : null}
        </ResourceState>
      </Panel>

      {editorOpen && weekDraft !== null && schedule.data !== null ? (
        <div className="modal-backdrop" role="presentation">
          <section className="modal work-plan-modal" role="dialog" aria-modal="true" aria-labelledby={editorTitleId}>
            <header className="modal__header">
              <div>
                <span className="modal__eyebrow">
                  {scope === 'mine' ? 'Eigen profiel' : 'Gebruikersbeheer'}
                </span>
                <h2 id={editorTitleId}>Beschikbaarheid aanpassen</h2>
              </div>
              <button
                className="icon-button"
                type="button"
                onClick={closeEditor}
                aria-label="Sluiten"
                disabled={saving}
              >
                <X size={18} />
              </button>
            </header>
            <div className="panel-body">
              <section className="stacked-section">
                <div className="section-heading">
                  <strong>Vaste weekplanning</strong>
                  <span>Stel per vaste weekdag de beschikbaarheid voor ochtend, middag en avond in.</span>
                </div>
                <div className="week-daypart-grid">
                  {daysOfWeek.map((dayOfWeek) => (
                    <article className="week-daypart-row" key={dayOfWeek}>
                      <strong>{dayLabel(dayOfWeek)}</strong>
                      <div className="daypart-planner-actions">
                        {dayParts.map((dayPart) => {
                          const state = weekDraft.find(
                            (day) => day.day_of_week === dayOfWeek && day.day_part === dayPart,
                          );
                          const isAvailable = state?.is_available ?? true;

                          return (
                            <button
                              className={isAvailable ? 'secondary-button' : 'danger-button'}
                              type="button"
                              key={dayPart}
                              disabled={saving}
                              aria-pressed={isAvailable}
                              onClick={() => updateWeekDayPart(dayOfWeek, dayPart, !isAvailable)}
                              title={`${dayLabel(dayOfWeek)} ${dayPartLabel(dayPart).toLowerCase()} standaard `
                                + `${isAvailable ? 'niet beschikbaar' : 'beschikbaar'} zetten`}
                            >
                              {dayPartLabel(dayPart)}: {isAvailable ? 'Aan' : 'Uit'}
                            </button>
                          );
                        })}
                      </div>
                    </article>
                  ))}
                </div>
              </section>

              <section className="stacked-section">
                <div className="section-heading">
                  <strong>Komende 2 weken</strong>
                  <span>Leg hier een afwijking vast zonder de vaste weekplanning te veranderen.</span>
                </div>
                <div className="daypart-planner">
                  {nextCalendarDays(14).map((date) => (
                    <article className="daypart-planner-row" key={date}>
                      <div>
                        <strong>{shortDateLabel(date)}</strong>
                        <span>{formatDateOnly(date)}</span>
                      </div>
                      <div className="daypart-planner-actions">
                        {dayParts.map((dayPart) => {
                          const isAvailable = scheduleForDatePart(schedule.data!, date, dayPart);

                          return (
                            <button
                              className={isAvailable ? 'secondary-button' : 'danger-button'}
                              type="button"
                              key={dayPart}
                              disabled={saving}
                              aria-pressed={isAvailable}
                              onClick={() => void planDayPart(date, dayPart, !isAvailable)}
                              title={`${dayPartLabel(dayPart)} wisselen naar `
                                + `${isAvailable ? 'niet beschikbaar' : 'beschikbaar'}`}
                            >
                              {dayPartLabel(dayPart)}: {isAvailable ? 'Aan' : 'Uit'}
                            </button>
                          );
                        })}
                      </div>
                    </article>
                  ))}
                </div>
              </section>

              {message ? <p className="form-note" role="status">{message}</p> : null}
              {actionError ? <p className="form-error" role="alert">{actionError}</p> : null}
            </div>
            <div className="actions-row">
              <button className="secondary-button" type="button" onClick={closeEditor} disabled={saving}>
                Sluiten
              </button>
              <button className="primary-button" type="button" onClick={() => void saveWeekPattern()} disabled={saving}>
                {saving ? 'Opslaan...' : 'Vaste weekplanning opslaan'}
              </button>
            </div>
          </section>
        </div>
      ) : null}
    </>
  );
}

function AvailabilityScheduleOverview({ schedule }: { schedule: AvailabilitySchedule }) {
  return (
    <div className="panel-body">
      <div className="summary-grid">
        <SummaryItem label="Vandaag" value={schedule.today.is_available ? 'Beschikbaar' : 'Niet beschikbaar'} />
        <SummaryItem label="Bron" value={availabilitySourceLabel(schedule.today.source)} />
      </div>
      <section className="stacked-section">
        <div className="section-heading">
          <strong>Vaste weekplanning</strong>
          <span>De standaard beschikbaarheid per ochtend, middag en avond.</span>
        </div>
        <table className="data-table compact-table">
          <thead>
            <tr>
              <th scope="col">Dag</th>
              {dayParts.map((dayPart) => <th scope="col" key={dayPart}>{dayPartLabel(dayPart)}</th>)}
            </tr>
          </thead>
          <tbody>
            {daysOfWeek.map((dayOfWeek) => (
              <tr key={dayOfWeek}>
                <th scope="row">{dayLabel(dayOfWeek)}</th>
                {dayParts.map((dayPart) => {
                  const state = scheduleForDayPart(schedule, dayOfWeek, dayPart);

                  return (
                    <td key={dayPart}>
                      <StatusPill
                        value={state.is_available ? 'available' : 'unavailable'}
                        tone={state.is_available ? 'good' : 'bad'}
                      />
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </section>
      <section className="stacked-section">
        <div className="section-heading">
          <strong>Komende 2 weken</strong>
          <span>Vaste planning, dagdeelafwijkingen en vakantieperiodes samengevoegd.</span>
        </div>
        <table className="data-table compact-table" aria-label="Beschikbaarheid komende twee weken">
          <thead>
            <tr>
              <th scope="col">Datum</th>
              {dayParts.map((dayPart) => <th scope="col" key={dayPart}>{dayPartLabel(dayPart)}</th>)}
            </tr>
          </thead>
          <tbody>
            {nextCalendarDays(14).map((date) => (
              <tr key={date}>
                <th scope="row">
                  {shortDateLabel(date)}
                  <span className="muted-text">{formatDateOnly(date)}</span>
                </th>
                {dayParts.map((dayPart) => {
                  const isAvailable = scheduleForDatePart(schedule, date, dayPart);

                  return (
                    <td key={dayPart}>
                      <StatusPill
                        value={isAvailable ? 'available' : 'unavailable'}
                        tone={isAvailable ? 'good' : 'bad'}
                      />
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  );
}

export function scheduleForDayPart(
  schedule: AvailabilitySchedule,
  dayOfWeek: number,
  dayPart: AvailabilityDayPart,
): AvailabilityScheduleDay {
  const specific = schedule.week_day_parts?.find(
    (day) => day.day_of_week === dayOfWeek && day.day_part === dayPart,
  );
  const fallback = schedule.week_pattern.find((day) => day.day_of_week === dayOfWeek);

  return specific ?? fallback ?? {
    day_of_week: dayOfWeek,
    day_part: dayPart,
    is_available: true,
    note: null,
    source: 'default',
  };
}

export function scheduleForDatePart(
  schedule: AvailabilitySchedule,
  dateValue: string,
  dayPart: AvailabilityDayPart,
): boolean {
  const applicableOverrides = schedule.overrides.filter((candidate) => dateInRange(dateValue, candidate));
  const override = mostRecentOverride(
    applicableOverrides.filter((candidate) => (candidate.day_part ?? 'all_day') === 'all_day'),
  ) ?? mostRecentOverride(
    applicableOverrides.filter((candidate) => candidate.day_part === dayPart),
  );
  if (override !== undefined) {
    return override.is_available;
  }

  const date = dateFromInput(dateValue);
  if (date === null) {
    return true;
  }

  const dayOfWeek = date.getDay() === 0 ? 7 : date.getDay();

  return scheduleForDayPart(schedule, dayOfWeek, dayPart).is_available;
}

function weekDayPartPattern(schedule: AvailabilitySchedule): AvailabilityScheduleDay[] {
  return daysOfWeek.flatMap((dayOfWeek) => dayParts.map((dayPart) => ({
    ...scheduleForDayPart(schedule, dayOfWeek, dayPart),
    day_of_week: dayOfWeek,
    day_part: dayPart,
  })));
}

function SummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function dayLabel(dayOfWeek: number): string {
  return ['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'][dayOfWeek - 1]
    ?? String(dayOfWeek);
}

function dayPartLabel(dayPart: AvailabilityDayPart): string {
  switch (dayPart) {
    case 'morning':
      return 'Ochtend';
    case 'afternoon':
      return 'Middag';
    case 'evening':
      return 'Avond';
  }
}

function availabilitySourceLabel(source: AvailabilitySchedule['today']['source']): string {
  switch (source) {
    case 'override':
      return 'Planning';
    case 'pattern':
    case 'week_pattern':
      return 'Wekelijkse planning';
    default:
      return 'Standaard beschikbaar';
  }
}

function nextCalendarDays(count: number): string[] {
  const today = dateFromInput(todayAmsterdamDateInputValue()) ?? new Date();
  today.setHours(12, 0, 0, 0);

  return Array.from({ length: count }, (_, index) => {
    const date = new Date(today);
    date.setDate(today.getDate() + index);

    return inputDateValue(date);
  });
}

function inputDateValue(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

function shortDateLabel(value: string): string {
  const date = dateFromInput(value);
  if (date === null) {
    return value;
  }

  return new Intl.DateTimeFormat('nl-NL', {
    weekday: 'short',
    day: '2-digit',
    month: '2-digit',
  }).format(date);
}

function dateInRange(dateValue: string, override: AvailabilityOverride): boolean {
  return dateValue >= override.starts_at && dateValue <= override.ends_at;
}

function mostRecentOverride(overrides: AvailabilityOverride[]): AvailabilityOverride | undefined {
  return overrides.reduce<AvailabilityOverride | undefined>((selected, candidate) => {
    if (selected === undefined) {
      return candidate;
    }

    return compareOverrideRecency(candidate, selected) > 0 ? candidate : selected;
  }, undefined);
}

function compareOverrideRecency(left: AvailabilityOverride, right: AvailabilityOverride): number {
  const leftUpdatedAt = parseTimestamp(left.updated_at);
  const rightUpdatedAt = parseTimestamp(right.updated_at);

  if (leftUpdatedAt !== null && rightUpdatedAt !== null) {
    return leftUpdatedAt - rightUpdatedAt || left.id.localeCompare(right.id);
  }
  if (leftUpdatedAt !== null) {
    return 1;
  }
  if (rightUpdatedAt !== null) {
    return -1;
  }

  // Older API payloads do not include updated_at and are already returned newest first.
  return 0;
}

function parseTimestamp(value?: string | null): number | null {
  if (value === undefined || value === null) {
    return null;
  }

  const timestamp = Date.parse(value);

  return Number.isNaN(timestamp) ? null : timestamp;
}

function dateFromInput(value: string): Date | null {
  const parts = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (parts === null) {
    return null;
  }

  return new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]), 12, 0, 0, 0);
}
