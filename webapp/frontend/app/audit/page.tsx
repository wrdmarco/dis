'use client';

import { AuditLogPage } from '../../src/features/audit/AuditLogPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.audit}>
      <AuditLogPage />
    </ProtectedShell>
  );
}
