'use client';

import { IncidentMapPage } from '../../src/features/incidents/IncidentMapPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['incidents.view']}>
      <IncidentMapPage />
    </ProtectedShell>
  );
}
