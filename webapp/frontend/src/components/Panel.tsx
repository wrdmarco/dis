export function Panel({ title, action, children }: { title: string; action?: React.ReactNode; children: React.ReactNode }) {
  return (
    <section className="panel">
      <header className="panel__header">
        <h2>{title}</h2>
        {action}
      </header>
      {children}
    </section>
  );
}

