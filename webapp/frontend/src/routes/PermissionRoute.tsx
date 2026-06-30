import { Navigate } from 'react-router-dom';
import { useAuth } from '../features/auth/AuthContext';

interface PermissionRouteProps {
  children: React.ReactNode;
  permissions?: string[];
  anyPermission?: boolean;
}

export function PermissionRoute({ children, permissions = [], anyPermission = false }: PermissionRouteProps) {
  const { canUseWebConsole, hasPermission } = useAuth();

  if (!canUseWebConsole()) {
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

export function DefaultRoute() {
  const { canUseWebConsole, hasPermission } = useAuth();

  if (!canUseWebConsole()) {
    return <Navigate to="/profile" replace />;
  }

  const target = [
    { to: '/dashboard', permissions: ['incidents.view', 'dispatch.view', 'status.view', 'assets.view'] },
    { to: '/incidents', permissions: ['incidents.view'] },
    { to: '/operationele-status', permissions: ['status.view'] },
    { to: '/users', permissions: ['users.view'] },
    { to: '/assets', permissions: ['assets.view'] },
    { to: '/certifications', permissions: ['certifications.view'] },
    { to: '/updates', permissions: ['updates.manage'] },
    { to: '/admin', permissions: ['settings.manage'] },
    { to: '/systeem', permissions: ['system.health'] },
  ].find((item) => item.permissions.every(hasPermission));

  return <Navigate to={target?.to ?? '/profile'} replace />;
}
