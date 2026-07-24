'use client';

import { QueuePage } from '../../src/features/queues/QueuePage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['system.health.view']}>
      <QueuePage />
    </ProtectedShell>
  );
}
