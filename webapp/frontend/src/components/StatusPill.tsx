import { statusLabel } from '../lib/statusLabels';

interface StatusPillProps {
  value: string;
  tone?: 'neutral' | 'good' | 'warn' | 'bad';
}

export function StatusPill({ value, tone = 'neutral' }: StatusPillProps) {
  return <span className={`status-pill status-pill--${tone}`}>{statusLabel(value)}</span>;
}
