'use client';

import { WallboardCreatePage } from '../../../src/features/wallboards/WallboardCreatePage';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['wallboards.manage']}>
      <WallboardCreatePage />
    </ProtectedShell>
  );
}
