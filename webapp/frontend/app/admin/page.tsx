'use client';

import { AdminPage } from '../../src/features/admin/AdminPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.admin}>
      <AdminPage />
    </ProtectedShell>
  );
}
