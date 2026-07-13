'use client';

import { AdminPage } from '../../src/features/admin/AdminPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['settings.manage', 'settings.push.tokens.manage', 'system.health.view', 'system.developer-access.manage']} anyPermission>
      <AdminPage />
    </ProtectedShell>
  );
}
