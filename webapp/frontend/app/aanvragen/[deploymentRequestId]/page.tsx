import { DeploymentRequestDetailPage } from '../../../src/features/deployment-requests/DeploymentRequestDetailPage';
import { webRouteAccess } from '../../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ deploymentRequestId: string }> }) {
  const { deploymentRequestId } = await params;

  return (
    <ProtectedShell {...webRouteAccess.deploymentRequests}>
      <DeploymentRequestDetailPage deploymentRequestId={deploymentRequestId} />
    </ProtectedShell>
  );
}
