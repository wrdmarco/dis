import { AssetFormPage } from '../../../src/features/assets/AssetFormPage';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['assets.view', 'assets.manage']}>
      <AssetFormPage />
    </ProtectedShell>
  );
}
