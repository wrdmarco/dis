'use client';

import { KnmiAdminPage } from '../../src/features/admin/KnmiAdminPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['settings.manage']}>
      <KnmiAdminPage />
    </ProtectedShell>
  );
}
