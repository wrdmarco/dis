'use client';

import { ExpiryPage } from '../../src/features/expiry/ExpiryPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['assets.view', 'certifications.view']} anyPermission>
      <ExpiryPage />
    </ProtectedShell>
  );
}
