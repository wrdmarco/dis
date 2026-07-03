'use client';

import { BackupPage } from '../../src/features/backups/BackupPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['backups.manage']}>
      <BackupPage />
    </ProtectedShell>
  );
}
