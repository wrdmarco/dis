import { UserEditPage } from '../../../../src/features/users/UserEditPage';
import { ProtectedShell } from '../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ userId: string }> }) {
  const { userId } = await params;

  return (
    <ProtectedShell permissions={['users.view', 'users.manage']}>
      <UserEditPage userId={userId} />
    </ProtectedShell>
  );
}
