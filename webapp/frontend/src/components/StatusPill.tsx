interface StatusPillProps {
  value: string;
  tone?: 'neutral' | 'good' | 'warn' | 'bad';
}

export function StatusPill({ value, tone = 'neutral' }: StatusPillProps) {
  return <span className={`status-pill status-pill--${tone}`}>{value.replaceAll('_', ' ')}</span>;
}

