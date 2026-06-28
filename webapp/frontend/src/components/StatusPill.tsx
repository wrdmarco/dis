interface StatusPillProps {
  value: string;
  tone?: 'neutral' | 'good' | 'warn' | 'bad';
}

export function StatusPill({ value, tone = 'neutral' }: StatusPillProps) {
  return <span className={`status-pill status-pill--${tone}`}>{statusLabel(value)}</span>;
}

function statusLabel(value: string): string {
  const labels: Record<string, string> = {
    available: 'Beschikbaar',
    unavailable: 'Niet beschikbaar',
    vacation: 'Vakantie',
    assigned: 'Toegewezen',
    en_route: 'Onderweg',
    on_scene: 'Op locatie',
    resting: 'Rust',
    suspended: 'Geblokkeerd',
  };

  return labels[value] ?? value.replaceAll('_', ' ');
}
