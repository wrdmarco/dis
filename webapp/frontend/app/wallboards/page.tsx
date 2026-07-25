'use client';

import { WallboardsAdminPage } from '../../src/features/wallboards/WallboardsAdminPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.wallboards}>
      <WallboardsAdminPage />
    </ProtectedShell>
  );
}
