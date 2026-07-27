import { DeploymentRequestPriorityDecisionsPage } from '../../src/features/admin/DeploymentRequestPriorityDecisionsPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.priorityDecisions}>
      <DeploymentRequestPriorityDecisionsPage />
    </ProtectedShell>
  );
}
