export const appTimeZone = 'Europe/Amsterdam';

const localDateTimePattern =
  /^(\d{4})-(\d{2})-(\d{2})[T ]([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d)(?:\.(\d{1,3})\d*)?)?$/;
const amsterdamWallClockFormatter = new Intl.DateTimeFormat('en-GB-u-ca-gregory-nu-latn', {
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
  timeZone: appTimeZone,
});
const amsterdamOffsetProbeHours = [-36, 0, 36] as const;

type WallClockDateTime = {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
  second: number;
  millisecond: number;
};

export function formatDateTime(value?: string | null): string {
  if (!value) {
    return '-';
  }

  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
    return formatDateOnly(value);
  }

  const serverLocalDateTime = parseServerLocalDateTime(value);
  if (serverLocalDateTime !== null) {
    return serverLocalDateTime;
  }

  const compactBackupDateTime = parseCompactBackupDateTime(value);
  if (compactBackupDateTime !== null) {
    return compactBackupDateTime;
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  try {
    return new Intl.DateTimeFormat('nl-NL', {
      dateStyle: 'short',
      timeStyle: 'medium',
      timeZone: appTimeZone,
    }).format(date);
  } catch {
    return new Intl.DateTimeFormat('nl-NL', {
      dateStyle: 'short',
      timeStyle: 'medium',
      timeZone: 'Europe/Amsterdam',
    }).format(date);
  }
}

export function formatDateOnly(value?: string | null): string {
  if (!value) {
    return '-';
  }

  const dateOnly = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  const date = dateOnly
    ? new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]), 12, 0, 0, 0)
    : new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('nl-NL', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: appTimeZone,
  }).format(date);
}

export function todayAmsterdamDateInputValue(): string {
  return dateInputValueInAmsterdam(new Date());
}

export function dateInputValueInAmsterdam(date: Date): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: appTimeZone,
  }).formatToParts(date);

  const part = (type: string) => parts.find((item) => item.type === type)?.value ?? '';

  return `${part('year')}-${part('month')}-${part('day')}`;
}

export function dateTimeLocalInputValue(value: unknown): string {
  if (typeof value !== 'string' || value.trim() === '') {
    return '';
  }

  const normalized = value.trim();
  const hasLocalDateTimeShape = localDateTimePattern.test(normalized);
  const localDateTime = parseWallClockDateTime(normalized);
  if (hasLocalDateTimeShape && localDateTime === null) {
    return '';
  }
  const parsed = localDateTime === null
    ? new Date(normalized)
    : new Date(amsterdamInstantForWallClock(localDateTime) ?? Number.NaN);

  if (Number.isNaN(parsed.getTime())) {
    return '';
  }

  const wallClock = amsterdamWallClockAt(parsed.getTime());

  return wallClock === null ? '' : formatDateTimeLocalValue(wallClock);
}

export function dateTimeLocalInputIsoValue(value: string): string {
  const wallClock = parseWallClockDateTime(value.trim());
  if (wallClock === null) {
    return '';
  }

  const timestamp = amsterdamInstantForWallClock(wallClock);

  return timestamp === null ? '' : new Date(timestamp).toISOString();
}

export function daysUntilAmsterdamDate(value?: string | null, now = new Date()): number | null {
  if (!value) {
    return null;
  }

  const dateOnly = value.match(/^(\d{4}-\d{2}-\d{2})/);
  if (dateOnly === null) {
    return null;
  }

  const deadlineOrdinal = dateInputOrdinal(dateOnly[1]);
  const todayOrdinal = dateInputOrdinal(dateInputValueInAmsterdam(now));
  if (deadlineOrdinal === null || todayOrdinal === null) {
    return null;
  }

  return deadlineOrdinal - todayOrdinal;
}

function dateInputOrdinal(value: string): number | null {
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (match === null) {
    return null;
  }

  const [, year, month, day] = match;
  const timestamp = Date.UTC(Number(year), Number(month) - 1, Number(day));
  const normalized = new Date(timestamp).toISOString().slice(0, 10);

  return normalized === value ? Math.trunc(timestamp / 86_400_000) : null;
}

function parseWallClockDateTime(value: string): WallClockDateTime | null {
  const match = value.match(localDateTimePattern);
  if (match === null) {
    return null;
  }

  const wallClock: WallClockDateTime = {
    year: Number(match[1]),
    month: Number(match[2]),
    day: Number(match[3]),
    hour: Number(match[4]),
    minute: Number(match[5]),
    second: Number(match[6] ?? '0'),
    millisecond: Number((match[7] ?? '').padEnd(3, '0')),
  };

  const ordinal = wallClockUtcOrdinal(wallClock);
  if (ordinal === null) {
    return null;
  }

  return wallClock;
}

function amsterdamInstantForWallClock(wallClock: WallClockDateTime): number | null {
  const ordinal = wallClockUtcOrdinal(wallClock);
  if (ordinal === null) {
    return null;
  }

  const candidateOffsets = new Set(
    amsterdamOffsetProbeHours.map((hours) => amsterdamOffsetMinutesAt(ordinal + hours * 3_600_000)),
  );
  const candidates = Array.from(candidateOffsets)
    .map((offsetMinutes) => ordinal - offsetMinutes * 60_000)
    .filter((candidate) => wallClocksMatch(amsterdamWallClockAt(candidate), wallClock))
    .sort((left, right) => left - right);

  // During the autumn overlap both instants are valid. Selecting the earliest
  // occurrence is deterministic; a non-existent spring-forward time has no
  // candidate and is rejected instead of silently shifting the user's input.
  return candidates[0] ?? null;
}

function amsterdamOffsetMinutesAt(timestamp: number): number {
  const minuteAlignedTimestamp = Math.trunc(timestamp / 60_000) * 60_000;
  const wallClock = amsterdamWallClockAt(minuteAlignedTimestamp);
  if (wallClock === null) {
    return 0;
  }

  const wallClockAsUtc = wallClockUtcOrdinal(wallClock);

  return wallClockAsUtc === null ? 0 : (wallClockAsUtc - minuteAlignedTimestamp) / 60_000;
}

function amsterdamWallClockAt(timestamp: number): WallClockDateTime | null {
  if (!Number.isFinite(timestamp)) {
    return null;
  }

  const parts = amsterdamWallClockFormatter.formatToParts(new Date(timestamp));
  const value = (type: Intl.DateTimeFormatPartTypes): number | null => {
    const part = parts.find((candidate) => candidate.type === type)?.value;

    return part === undefined ? null : Number(part);
  };
  const year = value('year');
  const month = value('month');
  const day = value('day');
  const hour = value('hour');
  const minute = value('minute');

  if (year === null || month === null || day === null || hour === null || minute === null) {
    return null;
  }

  return {
    year,
    month,
    day,
    hour,
    minute,
    second: 0,
    millisecond: 0,
  };
}

function wallClockUtcOrdinal(wallClock: WallClockDateTime): number | null {
  const date = new Date(0);
  date.setUTCFullYear(wallClock.year, wallClock.month - 1, wallClock.day);
  date.setUTCHours(wallClock.hour, wallClock.minute, wallClock.second, wallClock.millisecond);

  if (
    date.getUTCFullYear() !== wallClock.year
    || date.getUTCMonth() + 1 !== wallClock.month
    || date.getUTCDate() !== wallClock.day
    || date.getUTCHours() !== wallClock.hour
    || date.getUTCMinutes() !== wallClock.minute
    || date.getUTCSeconds() !== wallClock.second
    || date.getUTCMilliseconds() !== wallClock.millisecond
  ) {
    return null;
  }

  return date.getTime();
}

function wallClocksMatch(actual: WallClockDateTime | null, expected: WallClockDateTime): boolean {
  return actual !== null
    && actual.year === expected.year
    && actual.month === expected.month
    && actual.day === expected.day
    && actual.hour === expected.hour
    && actual.minute === expected.minute;
}

function formatDateTimeLocalValue(wallClock: WallClockDateTime): string {
  const pad = (value: number) => value.toString().padStart(2, '0');

  return `${wallClock.year.toString().padStart(4, '0')}-${pad(wallClock.month)}-${pad(wallClock.day)}`
    + `T${pad(wallClock.hour)}:${pad(wallClock.minute)}`;
}

function parseServerLocalDateTime(value: string): string | null {
  const match = value.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?$/);

  if (!match) {
    return null;
  }

  const [, year, month, day, hour, minute, second = '00'] = match;

  return `${day}-${month}-${year} ${hour}:${minute}:${second}`;
}

function parseCompactBackupDateTime(value: string): string | null {
  const match = value.match(/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/);

  if (!match) {
    return null;
  }

  const [, year, month, day, hour, minute, second] = match;
  const date = new Date(Date.UTC(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second)));

  return new Intl.DateTimeFormat('nl-NL', {
    dateStyle: 'short',
    timeStyle: 'medium',
    timeZone: appTimeZone,
  }).format(date);
}
