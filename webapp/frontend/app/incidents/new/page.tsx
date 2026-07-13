import { IncidentCreatePage } from '../../../src/features/incidents/IncidentCreatePage';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['incidents.view', 'incidents.manage']}>
      <IncidentCreatePage />
    </ProtectedShell>
  );
}
