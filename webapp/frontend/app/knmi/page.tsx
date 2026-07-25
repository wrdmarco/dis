'use client';

import { KnmiAdminPage } from '../../src/features/admin/KnmiAdminPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.knmi}>
      <KnmiAdminPage />
    </ProtectedShell>
  );
}
