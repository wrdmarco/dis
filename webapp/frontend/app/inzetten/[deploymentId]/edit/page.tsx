import { DeploymentEditPage } from '../../../../src/features/deployments/DeploymentEditPage';
import { ProtectedShell } from '../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ deploymentId: string }> }) {
  const { deploymentId } = await params;

  return (
    <ProtectedShell permissions={['deployments.manage']}>
      <DeploymentEditPage deploymentId={deploymentId} />
    </ProtectedShell>
  );
}
