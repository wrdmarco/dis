import { AlertTriangle, Loader2 } from 'lucide-react';

export function ResourceState({
  loading,
  error,
  empty,
  children,
}: {
  loading: boolean;
  error: string | null;
  empty: boolean;
  children: React.ReactNode;
}) {
  if (loading) {
    return (
      <div className="resource-state">
        <Loader2 aria-hidden className="spin" size={18} />
        <span>Gegevens laden</span>
      </div>
    );
  }

  if (error) {
    return (
      <div className="resource-state resource-state--error">
        <AlertTriangle aria-hidden size={18} />
        <span>{error}</span>
      </div>
    );
  }

  if (empty) {
    return <div className="resource-state">Geen gegevens beschikbaar</div>;
  }

  return <>{children}</>;
}

