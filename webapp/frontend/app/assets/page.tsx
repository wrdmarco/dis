'use client';

import { AssetsPage } from '../../src/features/assets/AssetsPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['assets.view']}>
      <AssetsPage />
    </ProtectedShell>
  );
}
