'use client';

import { BrandingPage } from '../../src/features/branding/BrandingPage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.branding}>
      <BrandingPage />
    </ProtectedShell>
  );
}
