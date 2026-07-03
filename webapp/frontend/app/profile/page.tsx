'use client';

import { ProfilePage } from '../../src/features/profile/ProfilePage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell allowProfileOnly>
      <ProfilePage />
    </ProtectedShell>
  );
}
