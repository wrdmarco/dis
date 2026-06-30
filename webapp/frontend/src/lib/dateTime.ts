const appTimeZone = 'Europe/Amsterdam';

export function formatDateTime(value?: string | null): string {
  if (!value) {
    return '-';
  }

  const serverLocalDateTime = parseServerLocalDateTime(value);
  if (serverLocalDateTime !== null) {
    return serverLocalDateTime;
  }

  try {
    return new Intl.DateTimeFormat('nl-NL', {
      dateStyle: 'short',
      timeStyle: 'medium',
      timeZone: appTimeZone,
    }).format(new Date(value));
  } catch {
    return new Intl.DateTimeFormat('nl-NL', {
      dateStyle: 'short',
      timeStyle: 'medium',
      timeZone: 'Europe/Amsterdam',
    }).format(new Date(value));
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
