import { DroneTypeFormPage } from '../../../../../src/features/assets/DroneTypeFormPage';
import { ProtectedShell } from '../../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ droneTypeId: string }> }) {
  const { droneTypeId } = await params;

  return (
    <ProtectedShell permissions={['assets.view', 'assets.manage']}>
      <DroneTypeFormPage droneTypeId={droneTypeId} />
    </ProtectedShell>
  );
}
