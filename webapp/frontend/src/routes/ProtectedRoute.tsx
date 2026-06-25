import { useEffect, useState } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../features/auth/AuthContext';

export function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { isAuthenticated, user, refreshMe } = useAuth();
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

  return <>{children}</>;
}

