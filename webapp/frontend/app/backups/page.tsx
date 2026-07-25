'use client';

import { BackupPage } from '../../src/features/backups/BackupPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.backups}>
      <BackupPage />
    </ProtectedShell>
  );
}
