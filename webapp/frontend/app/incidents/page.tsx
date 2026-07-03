'use client';

import { IncidentsPage } from '../../src/features/incidents/IncidentsPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['incidents.view']}>
      <IncidentsPage mode="active" />
    </ProtectedShell>
  );
}
