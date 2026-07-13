import { TeamFormPage } from '../../../src/features/teams/TeamFormPage';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['teams.manage']}>
      <TeamFormPage />
    </ProtectedShell>
  );
}
