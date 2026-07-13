import { RoleEditPage } from '../../../../src/features/roles/RoleEditPage';
import { ProtectedShell } from '../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ roleId: string }> }) {
  const { roleId } = await params;

  return (
    <ProtectedShell permissions={['roles.manage']}>
      <RoleEditPage roleId={roleId} />
    </ProtectedShell>
  );
}
