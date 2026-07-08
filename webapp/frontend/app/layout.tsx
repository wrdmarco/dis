import type { Metadata } from 'next';
import '../src/styles/global.css';
import { Providers } from './providers';

export const metadata: Metadata = {
  title: 'DIS',
  description: 'Drone Inzet Systeem',
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="nl">
      <body><Providers>{children}</Providers></body>
    </html>
  );
}
