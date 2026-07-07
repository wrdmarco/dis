'use client';

import { TestMapPage } from '../../src/features/test-map/TestMapPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['incidents.view']}>
      <TestMapPage />
    </ProtectedShell>
  );
}
