'use client';

import { TestAlertPage } from '../../src/features/test-alerts/TestAlertPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['incidents.dispatch.manage']}>
      <TestAlertPage />
    </ProtectedShell>
  );
}
