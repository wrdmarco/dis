'use client';

import { CertificationsPage } from '../../src/features/certifications/CertificationsPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.certifications}>
      <CertificationsPage />
    </ProtectedShell>
  );
}
