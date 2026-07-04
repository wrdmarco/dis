import { useId } from 'react';

export function Panel({ title, action, children }: { title: string; action?: React.ReactNode; children: React.ReactNode }) {
  const headingId = useId();

  return (
    <section className="panel" aria-labelledby={headingId}>
      <header className="panel__header">
        <h2 id={headingId}>{title}</h2>
        {action}
      </header>
      {children}
    </section>
  );
}
