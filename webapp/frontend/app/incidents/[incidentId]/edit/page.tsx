import { IncidentEditPage } from '../../../../src/features/incidents/IncidentEditPage';
import { ProtectedShell } from '../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ incidentId: string }> }) {
  const { incidentId } = await params;

  return (
    <ProtectedShell permissions={['incidents.view', 'incidents.manage']}>
      <IncidentEditPage incidentId={incidentId} />
    </ProtectedShell>
  );
}
