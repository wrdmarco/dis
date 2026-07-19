'use client';

import { WallboardsAdminPage } from '../../src/features/wallboards/WallboardsAdminPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['wallboards.manage']}>
      <WallboardsAdminPage />
    </ProtectedShell>
  );
}
