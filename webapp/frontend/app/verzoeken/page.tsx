'use client';

import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProductRequestsPage } from '../../src/features/product-requests/ProductRequestsPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.productRequests}>
      <ProductRequestsPage />
    </ProtectedShell>
  );
}
