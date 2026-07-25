'use client';

import { ExpiryPage } from '../../src/features/expiry/ExpiryPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.expiry}>
      <ExpiryPage />
    </ProtectedShell>
  );
}
