'use client';

import dynamic from 'next/dynamic';

const ClientApp = dynamic(() => import('../src/next/NextClientApp').then((module) => module.NextClientApp), {
  ssr: false,
  loading: () => <div className="resource-state" role="status" aria-live="polite">Laden...</div>,
});

export default function Page() {
  return <ClientApp />;
}
