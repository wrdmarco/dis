'use client';

import { usePathname } from 'next/navigation';
import { AuthProvider } from '../src/features/auth/AuthContext';
import { NotificationsProvider } from '../src/features/notifications/NotificationsContext';

export function Providers({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();

  if (pathname === '/wallboard') {
    return <>{children}</>;
  }

  return (
    <AuthProvider>
      <NotificationsProvider>{children}</NotificationsProvider>
    </AuthProvider>
  );
}
