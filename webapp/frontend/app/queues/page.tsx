'use client';

import { QueuePage } from '../../src/features/queues/QueuePage';
import { webRouteAccess } from '../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell {...webRouteAccess.queues}>
      <QueuePage />
    </ProtectedShell>
  );
}
