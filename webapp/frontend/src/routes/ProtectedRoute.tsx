import { useEffect, useState } from 'react';
import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../features/auth/AuthContext';

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, user, refreshMe } = useAuth();
  const location = useLocation();
  const [checking, setChecking] = useState(isAuthenticated && user === null);

  useEffect(() => {
    if (!isAuthenticated || user !== null) {
      setChecking(false);
      return;
    }
    refreshMe().finally(() => setChecking(false));
  }, [isAuthenticated, refreshMe, user]);

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />;
  }

  if (checking) {
    return <main className="boot-screen">Command Center initialiseren</main>;
  }

  const requiresTwoFactorSetup = user?.roles?.some((role) => role.requires_two_factor) === true && user.two_factor_enabled !== true;

  if (requiresTwoFactorSetup && location.pathname !== '/profile') {
    return <Navigate to="/profile" replace />;
  }

  return <>{children}</>;
}
