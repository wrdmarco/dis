import { TeamFormPage } from '../../../../src/features/teams/TeamFormPage';
import { ProtectedShell } from '../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ teamId: string }> }) {
  const { teamId } = await params;

  return (
    <ProtectedShell permissions={['teams.manage']}>
      <TeamFormPage teamId={teamId} />
    </ProtectedShell>
  );
}
