'use client';

import { SpeechAdminPage } from '../../src/features/speech/SpeechAdminPage';
import { ProtectedShell } from '../../src/next/RouteShell';

export default function Page() {
  return (
    <ProtectedShell permissions={['settings.manage']}>
      <SpeechAdminPage />
    </ProtectedShell>
  );
}
