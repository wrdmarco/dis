'use client';

import { DashboardPage } from '../../src/features/dashboard/DashboardPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['deployments.view', 'deployments.dispatch.view', 'status.view', 'assets.view']}>
      <DashboardPage />
    </ProtectedShell>
  );
}
