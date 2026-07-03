'use client';

import { Suspense } from 'react';
import { RegisterWizardPage } from '../../src/features/registration/RegisterWizardPage';

export default function Page() {
  return (
    <Suspense fallback={<main className="boot-screen">Registratie laden...</main>}>
      <RegisterWizardPage />
    </Suspense>
  );
}
