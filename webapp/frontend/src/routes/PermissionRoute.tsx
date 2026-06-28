import { Navigate } from 'react-router-dom';
import { useAuth } from '../features/auth/AuthContext';

interface PermissionRouteProps {
  children: React.ReactNode;
  permissions?: string[];
  anyPermission?: boolean;
}

export function PermissionRoute({ children, permissions = [], anyPermission = false }: PermissionRouteProps) {
  const { canUseAdminApp, hasPermission } = useAuth();

  if (!canUseAdminApp()) {
    return <Navigate to="/profile" replace />;
  }

  if (permissions.length > 0) {
    const allowed = anyPermission ? permissions.some(hasPermission) : permissions.every(hasPermission);
    if (!allowed) {
      return <Navigate to="/profile" replace />;
    }
  }

  return <>{children}</>;
}
