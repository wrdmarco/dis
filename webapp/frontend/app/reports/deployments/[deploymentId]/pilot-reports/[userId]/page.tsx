import { PilotReportEditorPage } from '../../../../../../src/features/reports/PilotReportEditorPage';
import { ProtectedShell } from '../../../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ deploymentId: string; userId: string }> }) {
  const { deploymentId, userId } = await params;

  return (
    <ProtectedShell permissions={['deployments.view', 'deployments.dispatch.view', 'deployments.manage']}>
      <PilotReportEditorPage deploymentId={deploymentId} userId={userId} />
    </ProtectedShell>
  );
}
