'use client';

import { AdminPage } from '../../src/features/admin/AdminPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['settings.manage']}>
      <AdminPage mode="forms" />
    </ProtectedShell>
  );
}
