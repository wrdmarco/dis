'use client';

import { DeploymentRequestCreatePage } from '../../../src/features/deployment-requests/DeploymentRequestCreatePage';
import { webRouteAccess } from '../../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.deploymentRequests}>
      <DeploymentRequestCreatePage />
    </ProtectedShell>
  );
}
