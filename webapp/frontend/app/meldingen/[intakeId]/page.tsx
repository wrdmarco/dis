import { IntakeDetailPage } from '../../../src/features/intakes/IntakeDetailPage';
import { webRouteAccess } from '../../../src/features/auth/webRouteAccess';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ intakeId: string }> }) {
  const { intakeId } = await params;

  return (
    <ProtectedShell {...webRouteAccess.intakes}>
      <IntakeDetailPage intakeId={intakeId} />
    </ProtectedShell>
  );
}
