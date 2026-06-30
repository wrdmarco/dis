const appTimeZone = 'Europe/Amsterdam';

export function formatDateTime(value?: string | null): string {
  if (!value) {
    return '-';
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

  return `${day}-${month}-${year} ${hour}:${minute}:${second}`;
}
