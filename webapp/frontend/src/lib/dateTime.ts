export const appTimeZone = 'Europe/Amsterdam';

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
