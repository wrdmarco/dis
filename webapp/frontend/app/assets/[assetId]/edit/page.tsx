import { AssetFormPage } from '../../../../src/features/assets/AssetFormPage';
import { ProtectedShell } from '../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ assetId: string }> }) {
  const { assetId } = await params;

  return (
    <ProtectedShell permissions={['assets.view', 'assets.manage']}>
      <AssetFormPage assetId={assetId} />
    </ProtectedShell>
  );
}
