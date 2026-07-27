import { DeploymentDetailPage } from '../../../src/features/deployments/DeploymentDetailPage';
import { webRouteAccess } from '../../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ deploymentId: string }> }) {
  const { deploymentId } = await params;

  return (
    <ProtectedShell {...webRouteAccess.deployments}>
      <DeploymentDetailPage deploymentId={deploymentId} />
    </ProtectedShell>
  );
}
