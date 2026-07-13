import { CertificationFormPage } from '../../../src/features/certifications/CertificationFormPage';
import { ProtectedShell } from '../../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['certifications.view', 'certifications.manage']}>
      <CertificationFormPage />
    </ProtectedShell>
  );
}
