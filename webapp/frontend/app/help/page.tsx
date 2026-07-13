'use client';

import { HelpPage } from '../../src/features/help/HelpPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell allowProfileOnly>
      <HelpPage />
    </ProtectedShell>
  );
}
