import type { Metadata } from 'next';
import { headers } from 'next/headers';
import '../src/styles/global.css';
import { Providers } from './providers';

export const metadata: Metadata = {
  title: 'DIS',
  description: 'Drone Inzet Systeem',
};

export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  // Reading request headers opts every application page into dynamic rendering,
  // which is required for Next.js to propagate the per-response CSP nonce.
  await headers();

  return (
    <html lang="nl">
      <body><Providers>{children}</Providers></body>
    </html>
  );
}
