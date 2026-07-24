import type {
  QueueMonitorAction,
  QueueMonitorFilter,
  QueueMonitorItem,
  QueueMonitorState,
  QueueMonitorStateFilter,
} from '../../types/api';

export type QueueStatusTone = 'neutral' | 'active' | 'success' | 'warning' | 'danger';

const DUTCH_DECIMAL = new Intl.NumberFormat('nl-NL', {
  maximumFractionDigits: 1,
  minimumFractionDigits: 0,
});

const ACTIVE_STATES = new Set<QueueMonitorState>(['pending', 'queued', 'processing', 'retrying']);

export function queueMonitorPath(
  queue: QueueMonitorFilter,
  state: QueueMonitorStateFilter,
  page: number,
  perPage: number,
): string {
  const params = new URLSearchParams({
    queue,
    state,
    page: String(Math.max(1, Math.trunc(page))),
    per_page: String(Math.min(100, Math.max(1, Math.trunc(perPage)))),
  });

  return `/admin/queues?${params.toString()}`;
}

export function queueStateLabel(state: QueueMonitorState): string {
  const labels: Record<QueueMonitorState, string> = {
    pending: 'Wachtend',
    queued: 'Wachtend',
    processing: 'Bezig',
    retrying: 'Wachtend',
    failed: 'Mislukt',
    completed: 'Verwerkt',
    cancelled: 'Geannuleerd',
  };

  return labels[state];
}

export function queueStateTone(state: QueueMonitorState): QueueStatusTone {
  if (state === 'processing') return 'active';
  if (state === 'completed') return 'success';
  if (state === 'failed') return 'danger';
  return 'neutral';
}

export function isVisibleQueueItem(item: QueueMonitorItem): boolean {
  return item.state === 'pending'
    || item.state === 'queued'
    || item.state === 'processing'
    || item.state === 'retrying'
    || item.state === 'failed';
}

export function queueActionForItem(item: QueueMonitorItem): QueueMonitorAction | null {
  const availableActions = Array.isArray(item.available_actions) ? item.available_actions : [];

  if (item.state === 'failed' && availableActions.includes('retry')) {
    return 'retry';
  }

  if (
    (item.state === 'pending' || item.state === 'queued' || item.state === 'retrying')
    && availableActions.includes('start')
  ) {
    return 'start';
  }

  return null;
}

export function queueActionPath(item: Pick<QueueMonitorItem, 'id' | 'queue'>, action: QueueMonitorAction): string {
  return `/admin/queues/${encodeURIComponent(item.queue)}/${encodeURIComponent(item.id)}/${action}`;
}

export function boundedQueueProgress(progress: number | null): number | null {
  if (typeof progress !== 'number' || !Number.isFinite(progress)) {
    return null;
  }

  return Math.min(100, Math.max(0, Math.round(progress)));
}

export function formatQueueDuration(milliseconds: number | null): string {
  if (typeof milliseconds !== 'number' || !Number.isFinite(milliseconds) || milliseconds < 0) {
    return '-';
  }

  if (milliseconds < 1_000) {
    return `${Math.round(milliseconds)} ms`;
  }

  const seconds = milliseconds / 1_000;
  if (seconds < 60) {
    return `${DUTCH_DECIMAL.format(seconds)} sec.`;
  }

  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = Math.round(seconds % 60);

  return remainingSeconds === 0
    ? `${minutes} min.`
    : `${minutes} min. ${remainingSeconds} sec.`;
}

export function formatQueueWait(item: QueueMonitorItem, generatedAt: string): string {
  const queuedAt = timestamp(item.queued_at);
  if (queuedAt === null) {
    return '-';
  }

  const stopAt = timestamp(item.started_at)
    ?? (ACTIVE_STATES.has(item.state) ? timestamp(generatedAt) : null)
    ?? timestamp(item.finished_at);

  if (stopAt === null || stopAt < queuedAt) {
    return '-';
  }

  return formatQueueDuration(stopAt - queuedAt);
}

export function formatQueueRuntime(item: QueueMonitorItem, generatedAt: string): string {
  if (item.duration_ms !== null) {
    return formatQueueDuration(item.duration_ms);
  }

  const startedAt = timestamp(item.started_at);
  if (startedAt === null || item.state !== 'processing') {
    return '-';
  }

  const measuredAt = timestamp(generatedAt);
  return measuredAt === null || measuredAt < startedAt
    ? '-'
    : formatQueueDuration(measuredAt - startedAt);
}

function timestamp(value: string | null): number | null {
  if (value === null || value.trim() === '') {
    return null;
  }

  const parsed = new Date(value).getTime();
  return Number.isNaN(parsed) ? null : parsed;
}
