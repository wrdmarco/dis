const appTimeZone = 'Europe/Amsterdam';

export function formatDateTime(value?: string | null): string {
  if (!value) {
    return '-';
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
