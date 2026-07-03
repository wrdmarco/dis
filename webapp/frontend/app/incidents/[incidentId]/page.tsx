import { IncidentDetailPage } from '../../../src/features/incidents/IncidentDetailPage';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ incidentId: string }> }) {
  const { incidentId } = await params;

  return (
    <ProtectedShell permissions={['incidents.view']}>
      <IncidentDetailPage incidentId={incidentId} />
    </ProtectedShell>
  );
}
