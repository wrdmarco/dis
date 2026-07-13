import { PilotReportEditorPage } from '../../../../../../src/features/reports/PilotReportEditorPage';
import { ProtectedShell } from '../../../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ incidentId: string; userId: string }> }) {
  const { incidentId, userId } = await params;

  return (
    <ProtectedShell permissions={['incidents.view', 'incidents.dispatch.view', 'incidents.manage']}>
      <PilotReportEditorPage incidentId={incidentId} userId={userId} />
    </ProtectedShell>
  );
}
