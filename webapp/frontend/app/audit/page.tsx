'use client';

import { AuditLogPage } from '../../src/features/audit/AuditLogPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['audit.view', 'status.audit.view']} anyPermission>
      <AuditLogPage />
    </ProtectedShell>
  );
}
