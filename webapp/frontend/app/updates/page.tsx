'use client';

import { UpdatesPage } from '../../src/features/updates/UpdatesPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['updates.manage']}>
      <UpdatesPage />
    </ProtectedShell>
  );
}
