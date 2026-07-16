'use client';

import { OsrmAdminPage } from '../../src/features/admin/OsrmAdminPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['system.health.view', 'system.routing.manage']} anyPermission>
      <OsrmAdminPage />
    </ProtectedShell>
  );
}
