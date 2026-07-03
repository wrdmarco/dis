'use client';

import { HomeRedirect, ProtectedShell } from '../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell allowProfileOnly>
      <HomeRedirect />
    </ProtectedShell>
  );
}
