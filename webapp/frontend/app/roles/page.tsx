'use client';

import { RolesPage } from '../../src/features/roles/RolesPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['roles.manage']}>
      <RolesPage />
    </ProtectedShell>
  );
}
