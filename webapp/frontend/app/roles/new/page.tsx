import { RoleCreatePage } from '../../../src/features/roles/RoleCreatePage';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['roles.manage']}>
      <RoleCreatePage />
    </ProtectedShell>
  );
}
