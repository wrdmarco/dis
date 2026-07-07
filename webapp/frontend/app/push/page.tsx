'use client';

import { PushPage } from '../../src/features/push/PushPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['settings.push.manual.send']}>
      <PushPage />
    </ProtectedShell>
  );
}
