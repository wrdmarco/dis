'use client';

import { TeamsPage } from '../../src/features/teams/TeamsPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['teams.manage']}>
      <TeamsPage />
    </ProtectedShell>
  );
}
