import { CertificationFormPage } from '../../../../src/features/certifications/CertificationFormPage';
import { ProtectedShell } from '../../../../src/next/RouteShell';

export default async function Page({ params }: { params: Promise<{ certificationId: string }> }) {
  const { certificationId } = await params;

  return (
    <ProtectedShell permissions={['certifications.view', 'certifications.manage']}>
      <CertificationFormPage certificationId={certificationId} />
    </ProtectedShell>
  );
}
