'use client';

import { ReportsPage } from '../../src/features/reports/ReportsPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['deployments.view', 'deployments.dispatch.view']}>
      <ReportsPage />
    </ProtectedShell>
  );
}
