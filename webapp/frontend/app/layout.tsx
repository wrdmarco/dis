import type { Metadata } from 'next';
import '../src/styles/global.css';

export const metadata: Metadata = {
  title: 'D.I.S Operationeel Beeld',
  description: 'D.I.S Command Center',
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="nl">
      <body>{children}</body>
    </html>
  );
}
