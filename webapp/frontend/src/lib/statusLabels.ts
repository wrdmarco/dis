const DUTCH_STATUS_LABELS = {
  accepted: 'Geaccepteerd',
  active: 'Actief',
  approved: 'Goedgekeurd',
  assigned: 'Toegewezen',
  available: 'Beschikbaar',
  blocked: 'Geblokkeerd',
  cancelled: 'Geannuleerd',
  closed: 'Gesloten',
  completed: 'Voltooid',
  critical: 'Kritiek',
  declined: 'Afgewezen',
  dispatching: 'Alarmeren',
  draft: 'Concept',
  en_route: 'Onderweg',
  escalated: 'Opgeschaald',
  failed: 'Mislukt',
  final: 'Definitief',
  full: 'Vol',
  high: 'Hoog',
  in_progress: 'In uitvoering',
  low: 'Laag',
  no_response: 'Geen reactie',
  normal: 'Normaal',
  open: 'Open',
  expired: 'Verlopen',
  maintenance: 'Onderhoud',
  maintenance_overdue: 'Onderhoud verlopen',
  paired: 'Gekoppeld',
  partial: 'Gedeeltelijk',
  on_scene: 'Op locatie',
  pending: 'In behandeling',
  queued: 'In wachtrij',
  queued_for_push: 'Klaargezet voor push',
  ready: 'Gereed',
  resolved: 'Afgerond',
  resting: 'Rust',
  retired: 'Uit dienst',
  revoked: 'Ingetrokken',
  scheduled: 'Gepland',
  sent: 'Verstuurd',
  store_review: 'Storebeoordeling',
  submitted: 'Ingediend',
  suspended: 'Geblokkeerd',
  unavailable: 'Niet beschikbaar',
  unknown: 'Onbekend',
  vacation: 'Vakantie',
} as const satisfies Record<string, string>;

export function statusLabel(value: string): string {
  const translated = DUTCH_STATUS_LABELS[value as keyof typeof DUTCH_STATUS_LABELS];
  if (translated !== undefined) {
    return translated;
  }

  const humanized = value.replaceAll('_', ' ');
  return humanized === '' ? humanized : humanized[0].toLocaleUpperCase('nl-NL') + humanized.slice(1);
}
