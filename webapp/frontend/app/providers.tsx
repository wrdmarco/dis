'use client';

import { AuthProvider } from '../src/features/auth/AuthContext';

export function Providers({ children }: { children: React.ReactNode }) {
  return <AuthProvider>{children}</AuthProvider>;
}
