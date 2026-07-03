'use client';

import { BrandingPage } from '../../src/features/branding/BrandingPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['settings.manage']}>
      <BrandingPage />
    </ProtectedShell>
  );
}
