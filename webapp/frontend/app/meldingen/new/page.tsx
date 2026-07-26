'use client';

import { IntakeCreatePage } from '../../../src/features/intakes/IntakeCreatePage';
import { webRouteAccess } from '../../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.intakes}>
      <IntakeCreatePage />
    </ProtectedShell>
  );
}
