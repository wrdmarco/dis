'use client';

import { UsersPage } from '../../src/features/users/UsersPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['users.view']}>
      <UsersPage />
    </ProtectedShell>
  );
}
