'use client';

import { SystemPage } from '../../src/features/system/SystemPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['system.health']}>
      <SystemPage />
    </ProtectedShell>
  );
}
