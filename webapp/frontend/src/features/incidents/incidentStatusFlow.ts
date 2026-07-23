import type { Incident } from '../../types/api';

export type IncidentLifecycleAction = 'cancel' | 'close';

interface UserWithRoleNames {
  roles?: Array<{ name: string }>;
}

export function isSystemAdministrator(user?: UserWithRoleNames | null): boolean {
  return user?.roles?.some((role) => role.name === 'system-administrator') ?? false;
}

export function incidentLifecycleActionForStatus(status: Incident['status']): IncidentLifecycleAction | null {
  if (status === 'draft' || status === 'active') {
    return 'cancel';
  }

  if (status === 'in_progress') {
    return 'close';
  }

  return null;
}

export function incidentStatusPayload(
  status: Incident['status'],
  includeStatus: boolean,
): Partial<Pick<Incident, 'status'>> {
  return includeStatus ? { status } : {};
}
