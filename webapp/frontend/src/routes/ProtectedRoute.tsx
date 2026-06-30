import { useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../features/auth/AuthContext';
import { ApiClientError } from '../lib/apiClient';

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, user, refreshMe } = useAuth();
  const location = useLocation();
  const [checking, setChecking] = useState(isAuthenticated && user === null);
  const [refreshError, setRefreshError] = useState<string | null>(null);

  useEffect(() => {
    if (!isAuthenticated || user !== null) {
      setChecking(false);
      setRefreshError(null);
      return;
    }
    refreshMe()
      .then(() => setRefreshError(null))
      .catch((error: unknown) => {
        if (error instanceof ApiClientError && error.status === 401) {
          setRefreshError(null);
          return;
        }
        setRefreshError('Het systeem is tijdelijk niet bereikbaar. Je login blijft bewaard.');
      })
      .finally(() => setChecking(false));
  }, [isAuthenticated, refreshMe, user]);

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (checking) {
    return <main className="boot-screen">Command Center initialiseren</main>;
  }

  if (user === null) {
    if (refreshError !== null) {
      return <main className="boot-screen">{refreshError}</main>;
    }

    return <Navigate to="/login" replace />;
  }

  const requiresTwoFactorSetup = user?.roles?.some((role) => role.requires_two_factor) === true && user.two_factor_enabled !== true;

  if (requiresTwoFactorSetup && location.pathname !== '/profile') {
    return <Navigate to="/profile" replace />;
  }

  return <>{children}</>;
}
