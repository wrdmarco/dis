'use client';

import { DeploymentMapPage } from '../../src/features/deployments/DeploymentMapPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['operational-map.view', 'deployments.view']}>
      <DeploymentMapPage />
    </ProtectedShell>
  );
}
