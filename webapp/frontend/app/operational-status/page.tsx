'use client';

import { StatusPage } from '../../src/features/status/StatusPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['status.view']}>
      <StatusPage />
    </ProtectedShell>
  );
}
