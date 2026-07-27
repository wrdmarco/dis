'use client';

import { DeploymentsPage } from '../../src/features/deployments/DeploymentsPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.deployments}>
      <DeploymentsPage mode="active" />
    </ProtectedShell>
  );
}
