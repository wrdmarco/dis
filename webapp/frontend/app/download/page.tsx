'use client';

import { AndroidDownloadPage } from '../../src/features/public/AndroidDownloadPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell allowProfileOnly>
      <AndroidDownloadPage />
    </ProtectedShell>
  );
}
