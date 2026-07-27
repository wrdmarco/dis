'use client';

import { DeploymentRequestListPage } from '../../src/features/deployment-requests/DeploymentRequestListPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.deploymentRequests}>
      <DeploymentRequestListPage />
    </ProtectedShell>
  );
}
