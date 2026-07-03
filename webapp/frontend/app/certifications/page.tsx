'use client';

import { CertificationsPage } from '../../src/features/certifications/CertificationsPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['certifications.view']}>
      <CertificationsPage />
    </ProtectedShell>
  );
}
