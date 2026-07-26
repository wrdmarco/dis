'use client';

import { IntakeListPage } from '../../src/features/intakes/IntakeListPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.intakes}>
      <IntakeListPage />
    </ProtectedShell>
  );
}
