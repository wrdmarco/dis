import type { Deployment } from '../../types/api';

export type DeploymentLifecycleAction = 'cancel' | 'close';

interface UserWithRoleNames {
  roles?: Array<{ name: string }>;
}

export function isSystemAdministrator(user?: UserWithRoleNames | null): boolean {
  return user?.roles?.some((role) => role.name === 'system-administrator') ?? false;
}

export function deploymentLifecycleActionForStatus(
  status: Deployment['status'],
): DeploymentLifecycleAction | null {
  if (status === 'draft' || status === 'active') {
    return 'cancel';
  }

  if (status === 'in_progress') {
    return 'close';
  }

  return null;
}

export function deploymentStatusPayload(
  status: Deployment['status'],
  includeStatus: boolean,
): Partial<Pick<Deployment, 'status'>> {
  return includeStatus ? { status } : {};
}
