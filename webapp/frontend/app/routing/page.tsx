'use client';

import { OsrmAdminPage } from '../../src/features/admin/OsrmAdminPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.routing}>
      <OsrmAdminPage />
    </ProtectedShell>
  );
}
