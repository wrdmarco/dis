import type { ApiResponse } from '../../types/api';

export const defaultTestAlertScope = 'self' as const;

export type TestAlertScope = 'self' | 'all_online';

export interface TestAlertSummary {
  scope: TestAlertScope;
  recipient_count: number;
  queued_token_count: number;
  skipped_user_count: number;
  failed_user_count: number;
}

export function testAlertSuccessMessage(summary: TestAlertSummary): string {
  if (summary.scope === 'all_online') {
    return summary.failed_user_count > 0
      ? `Bereikbaarheidstest gestart voor ${summary.recipient_count} online operator${summary.recipient_count === 1 ? '' : 's'}; ${summary.failed_user_count} gebruiker${summary.failed_user_count === 1 ? '' : 's'} konden niet worden klaargezet.`
      : `Bereikbaarheidstest gestart voor ${summary.recipient_count} online operator${summary.recipient_count === 1 ? '' : 's'}.`;
  }

  return summary.queued_token_count === 1
    ? 'Persoonlijke proefmelding klaargezet voor je actieve gekoppelde app.'
    : `Persoonlijke proefmelding klaargezet voor ${summary.queued_token_count} actieve gekoppelde apps.`;
}

export function readTestAlertSummary(meta: ApiResponse<unknown>['meta']): TestAlertSummary | null {
  if (meta === undefined || !isRecord(meta)) {
    return null;
  }

  const scope = meta.scope;
  if (scope !== 'self' && scope !== 'all_online') {
    return null;
  }

  const counts = [meta.recipient_count, meta.queued_token_count, meta.skipped_user_count, meta.failed_user_count];
  if (!counts.every(isNonNegativeInteger)) {
    return null;
  }

  return {
    scope,
    recipient_count: meta.recipient_count as number,
    queued_token_count: meta.queued_token_count as number,
    skipped_user_count: meta.skipped_user_count as number,
    failed_user_count: meta.failed_user_count as number,
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isNonNegativeInteger(value: unknown): boolean {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0;
}
