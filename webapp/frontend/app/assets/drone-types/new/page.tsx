import { DroneTypeFormPage } from '../../../../src/features/assets/DroneTypeFormPage';
import { ProtectedShell } from '../../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['assets.view', 'assets.manage']}>
      <DroneTypeFormPage />
    </ProtectedShell>
  );
}
