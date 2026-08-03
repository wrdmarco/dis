import type {
  Deployment,
  DeploymentPilot,
  DeploymentPilotCandidate,
  DeploymentPilotLinkResult,
  DispatchRequest,
  PaginationMeta,
  Team,
} from '../../types/api';

const pilotMutationStatuses: Deployment['status'][] = ['active', 'dispatching', 'in_progress'];

export function allowsDeploymentPilotMutations(
  deployment?: Pick<Deployment, 'status' | 'is_test'> | null,
): boolean {
  return deployment !== null
    && deployment !== undefined
    && deployment.is_test !== true
    && pilotMutationStatuses.includes(deployment.status);
}

export function deploymentPilotTeamsLabel(teams?: Team[]): string {
  if (!teams || teams.length === 0) {
    return 'Geen team vermeld';
  }

  return teams.map((team) => team.code || team.name).join(', ');
}

export function filterDeploymentPilotCandidates(
  candidates: DeploymentPilotCandidate[],
  search: string,
): DeploymentPilotCandidate[] {
  const query = search.trim().toLocaleLowerCase('nl-NL');
  if (query === '') {
    return candidates;
  }

  return candidates.filter((candidate) => [
    candidate.name,
    candidate.email,
  ].some((value) => value.toLocaleLowerCase('nl-NL').includes(query)));
}

export function deploymentPilotCandidatePagination(
  meta: unknown,
  visibleCount: number,
): PaginationMeta {
  if (meta !== null && typeof meta === 'object'
    && 'current_page' in meta && positiveInteger(meta.current_page)
    && 'last_page' in meta && positiveInteger(meta.last_page)
    && 'per_page' in meta && positiveInteger(meta.per_page)
    && 'total' in meta && nonNegativeInteger(meta.total)) {
    return {
      current_page: Math.min(meta.current_page, meta.last_page),
      last_page: meta.last_page,
      per_page: meta.per_page,
      total: meta.total,
    };
  }

  return {
    current_page: 1,
    last_page: 1,
    per_page: Math.max(1, visibleCount),
    total: visibleCount,
  };
}

export function deploymentAdditionalInfoRecipientCount(
  dispatch: DispatchRequest,
  pilots: DeploymentPilot[],
): number {
  const userIds = new Set<string>();

  for (const recipient of dispatch.recipients ?? []) {
    const isAttending = recipient.response_status === 'accepted'
      || ['en_route', 'on_scene'].includes(recipient.user?.statuses?.[0]?.status ?? '');
    if (isAttending && typeof recipient.user_id === 'string' && recipient.user_id !== '') {
      userIds.add(recipient.user_id);
    }
  }

  for (const pilot of pilots) {
    if (pilot.source === 'manual' && typeof pilot.user_id === 'string' && pilot.user_id !== '') {
      userIds.add(pilot.user_id);
    }
  }

  return userIds.size;
}

export function deploymentPilotLinkSuccessMessage(
  result: DeploymentPilotLinkResult,
  name: string,
  meta?: unknown,
): string {
  const queuedTokens = notificationQueuedTokens(meta)
    ?? result.notification_queued_tokens
    ?? result.notification?.queued_tokens;
  if (typeof queuedTokens === 'number' && Number.isInteger(queuedTokens) && queuedTokens >= 0) {
    return queuedTokens > 0
      ? `${name} is gekoppeld en het informatieve pushbericht is ingepland; er klinkt geen alarm.`
      : `${name} is gekoppeld, maar het informatieve pushbericht kon niet worden ingepland.`;
  }

  return `${name} is gekoppeld; het informatieve pushbericht wordt indien bereikbaar verzonden zonder alarm.`;
}

function notificationQueuedTokens(meta: unknown): number | undefined {
  if (meta === null || typeof meta !== 'object' || !('notification_queued_tokens' in meta)) {
    return undefined;
  }

  const value = meta.notification_queued_tokens;
  return typeof value === 'number' ? value : undefined;
}

function positiveInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value > 0;
}

function nonNegativeInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0;
}
