'use client';

import { SystemPage } from '../../src/features/system/SystemPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.system}>
      <SystemPage />
    </ProtectedShell>
  );
}
