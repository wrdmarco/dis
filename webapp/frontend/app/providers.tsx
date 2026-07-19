'use client';

import { usePathname } from 'next/navigation';
import { AuthProvider } from '../src/features/auth/AuthContext';

export function Providers({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();

  if (pathname === '/wallboard') {
    return <>{children}</>;
  }

  return <AuthProvider>{children}</AuthProvider>;
}
