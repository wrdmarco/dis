'use client';

import { AssetsPage } from '../../src/features/assets/AssetsPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.assets}>
      <AssetsPage />
    </ProtectedShell>
  );
}
