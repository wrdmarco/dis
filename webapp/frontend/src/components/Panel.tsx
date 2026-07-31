import { useId } from 'react';

interface PanelProps {
  action?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
  title: string;
}

export function Panel({ title, action, children, className }: PanelProps) {
  const headingId = useId();

  return (
    <section className={className ? `panel ${className}` : 'panel'} aria-labelledby={headingId}>
      <header className="panel__header">
        <h2 id={headingId}>{title}</h2>
        {action}
      </header>
      {children}
    </section>
  );
}
