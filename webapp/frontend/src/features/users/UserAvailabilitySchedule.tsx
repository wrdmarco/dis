'use client';

import { useEffect, useRef } from 'react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { formatDateOnly, todayAmsterdamDateInputValue } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import type { AvailabilityOverride, AvailabilitySchedule, AvailabilityScheduleDay } from '../../types/api';

type AvailabilityDayPart = 'morning' | 'afternoon' | 'evening';

const dayParts: AvailabilityDayPart[] = ['morning', 'afternoon', 'evening'];
const daysOfWeek = [1, 2, 3, 4, 5, 6, 7];

interface UserAvailabilityScheduleProps {
  userId?: string;
  canView: boolean;
  refreshVersion?: number;
}

export function UserAvailabilitySchedule({
  userId,
  canView,
  refreshVersion = 0,
}: UserAvailabilityScheduleProps) {
  const enabled = canView && userId !== undefined;
  const schedule = useApiResource<AvailabilitySchedule>(
    `/availability-statuses/users/${encodeURIComponent(userId ?? '')}/availability-schedule`,
    enabled,
  );
  const silentReloadSchedule = schedule.silentReload;
  const previousRefreshVersion = useRef(refreshVersion);

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

  return (
    <Panel title="Wekelijkse planning">
      <ResourceState loading={schedule.loading} error={schedule.error} empty={schedule.data === null}>
        {schedule.data !== null ? (
          <div className="panel-body">
            <div className="summary-grid">
              <SummaryItem label="Vandaag" value={schedule.data.today.is_available ? 'Beschikbaar' : 'Niet beschikbaar'} />
              <SummaryItem label="Bron" value={availabilitySourceLabel(schedule.data.today.source)} />
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
                        const state = scheduleForDayPart(schedule.data!, dayOfWeek, dayPart);

                        return (
                          <td key={dayPart}>
                            <StatusPill value={state.is_available ? 'available' : 'unavailable'} tone={state.is_available ? 'good' : 'bad'} />
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
                        const isAvailable = scheduleForDatePart(schedule.data!, date, dayPart);

                        return (
                          <td key={dayPart}>
                            <StatusPill value={isAvailable ? 'available' : 'unavailable'} tone={isAvailable ? 'good' : 'bad'} />
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          </div>
        ) : null}
      </ResourceState>
    </Panel>
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
    applicableOverrides.filter((candidate) => candidate.day_part === dayPart),
  ) ?? mostRecentOverride(
    applicableOverrides.filter((candidate) => (candidate.day_part ?? 'all_day') === 'all_day'),
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

function SummaryItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span>{label}</span>
      <strong>{value}</strong>
    </div>
  );
}

function dayLabel(dayOfWeek: number): string {
  return ['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'][dayOfWeek - 1] ?? String(dayOfWeek);
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
