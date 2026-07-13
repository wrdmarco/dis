import { UserDetailPage } from '../../../src/features/users/UserDetailPage';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ userId: string }> }) {
  const { userId } = await params;

  return (
    <ProtectedShell permissions={['users.view']}>
      <UserDetailPage userId={userId} />
    </ProtectedShell>
  );
}
