'use client';

import { useEffect, useMemo, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { CommandLayout } from '../app/CommandLayout';
import { ApiClientError } from '../lib/apiClient';
import { useAuth } from '../features/auth/AuthContext';

interface ProtectedShellProps {
  children: React.ReactNode;
  permissions?: string[];
  anyPermission?: boolean;
  allowProfileOnly?: boolean;
}

const homeRedirectCandidates = [
  { to: '/dashboard', permissions: ['incidents.view', 'incidents.dispatch.view', 'status.view', 'assets.view'] },
  { to: '/incidents', permissions: ['incidents.view'] },
  { to: '/operational-status', permissions: ['status.view'] },
  { to: '/users', permissions: ['users.view'] },
  { to: '/address-book', permissions: ['address-book.view'] },
  { to: '/assets', permissions: ['assets.view'] },
  { to: '/certifications', permissions: ['certifications.view'] },
  { to: '/forms', permissions: ['settings.manage'] },
  { to: '/admin', permissions: ['settings.manage'] },
  { to: '/admin', permissions: ['settings.push.tokens.manage'] },
  { to: '/system', permissions: ['system.health'] },
] as const;

export function ProtectedShell({ children, permissions = [], anyPermission = false, allowProfileOnly = false }: ProtectedShellProps) {
  const { isAuthenticated, user, refreshMe, canUseWebConsole, hasPermission } = useAuth();
  const pathname = usePathname();
  const router = useRouter();
  const [checking, setChecking] = useState(isAuthenticated && user === null);
  const [refreshError, setRefreshError] = useState<string | null>(null);

  useEffect(() => {
    if (!isAuthenticated) {
      router.replace('/login');
      return;
    }

    if (user !== null) {
      setChecking(false);
      setRefreshError(null);
      return;
    }

    setChecking(true);
    refreshMe()
      .then(() => setRefreshError(null))
      .catch((error: unknown) => {
        if (error instanceof ApiClientError && error.status === 401) {
          setRefreshError(null);
          router.replace('/login');
          return;
        }
        setRefreshError('Het systeem is tijdelijk niet bereikbaar. Je login blijft bewaard.');
      })
      .finally(() => setChecking(false));
  }, [isAuthenticated, refreshMe, router, user]);

  const requiresTwoFactorSetup = user?.mfa_required === true && user.two_factor_enabled !== true;
  const allowed = useMemo(() => {
    if (allowProfileOnly) {
      return true;
    }

    if (!canUseWebConsole()) {
      return false;
    }

    if (permissions.length === 0) {
      return true;
    }

    return anyPermission ? permissions.some(hasPermission) : permissions.every(hasPermission);
  }, [allowProfileOnly, anyPermission, canUseWebConsole, hasPermission, permissions]);

  useEffect(() => {
    if (checking || user === null) {
      return;
    }

    if (requiresTwoFactorSetup && pathname !== '/profile') {
      router.replace('/profile');
      return;
    }

    if (!allowed) {
      router.replace('/profile');
    }
  }, [allowed, checking, pathname, requiresTwoFactorSetup, router, user]);

  if (!isAuthenticated || checking) {
    return <main className="boot-screen">Command Center initialiseren</main>;
  }

  if (user === null) {
    return <main className="boot-screen">{refreshError ?? 'Command Center initialiseren'}</main>;
  }

  if ((requiresTwoFactorSetup && pathname !== '/profile') || !allowed) {
    return <main className="boot-screen">Doorsturen...</main>;
  }

  return <CommandLayout>{children}</CommandLayout>;
}

export function HomeRedirect() {
  const { user, canUseWebConsole, hasPermission } = useAuth();
  const router = useRouter();
  const target = useMemo(() => {
    if (user === null) {
      return null;
    }

    if (!canUseWebConsole()) {
      return '/profile';
    }

    return homeRedirectCandidates.find((item) => item.permissions.every(hasPermission))?.to ?? '/profile';
  }, [canUseWebConsole, hasPermission, user]);

  useEffect(() => {
    if (target !== null) {
      router.replace(target);
    }
  }, [router, target]);

  return <main className="boot-screen">Doorsturen...</main>;
}
