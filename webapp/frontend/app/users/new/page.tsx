import { UserCreatePage } from '../../../src/features/users/UserCreatePage';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['users.view', 'users.manage']}>
      <UserCreatePage />
    </ProtectedShell>
  );
}
