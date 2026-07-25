import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { QueueMonitorItem, QueueMonitorState } from '../src/types/api';
import {
  boundedQueueProgress,
  formatQueueDuration,
  formatQueueRuntime,
  formatQueueWait,
  isVisibleQueueItem,
  queueActionForItem,
  queueActionPath,
  queueMonitorPath,
  queueStateLabel,
  queueStateTone,
} from '../src/features/queues/queuePresentation';
import {
  QUEUE_DEFAULT_POLL_INTERVAL_MS,
  QUEUE_MAX_POLL_INTERVAL_MS,
  QUEUE_MIN_POLL_INTERVAL_MS,
  queuePollIntervalMs,
  startQueuePolling,
} from '../src/features/queues/queuePolling';

function queueItem(
  state: QueueMonitorState,
  availableActions: QueueMonitorItem['available_actions'] = [],
): QueueMonitorItem {
  return {
    id: 'safe-reference',
    queue: 'push',
    workload_type: 'push_notification',
    label: 'Pushmelding',
    state,
    progress_percent: state === 'processing' ? 35 : null,
    queued_at: '2026-07-24T10:00:00Z',
    started_at: state === 'processing' ? '2026-07-24T10:00:03Z' : null,
    next_attempt_at: null,
    finished_at: state === 'failed' ? '2026-07-24T10:00:09Z' : null,
    attempts: 1,
    error_code: state === 'failed' ? 'delivery_failed' : null,
    duration_ms: null,
    available_actions: availableActions,
  };
}

test('exposes Wachtrijen as a protected management page immediately before Systeem', () => {
  const route = readFileSync(new URL('../app/queues/page.tsx', import.meta.url), 'utf8');
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const routeShell = readFileSync(new URL('../src/next/RouteShell.tsx', import.meta.url), 'utf8');
  const queueIndex = navigation.indexOf("to: '/queues', label: 'Wachtrijen'");
  const systemIndex = navigation.indexOf("to: '/system', label: 'Systeem'");

  expect(route).toContain('<ProtectedShell {...webRouteAccess.queues}>');
  expect(queueIndex).toBeGreaterThan(-1);
  expect(systemIndex).toBeGreaterThan(queueIndex);
  expect(navigation).toContain("'/queues': () => import('../features/queues/QueuePage')");
  expect(navigation).toContain("{ to: '/queues', label: 'Wachtrijen', icon: ListTodo, ...webRouteAccess.queues }");
  expect(routeShell).toContain("{ to: '/queues', ...webRouteAccess.queues }");
});

test('renders one minimal work list with only the three operational states', () => {
  const page = readFileSync(new URL('../src/features/queues/QueuePage.tsx', import.meta.url), 'utf8');

  expect(page).toContain("title=\"Wachtrij\"");
  expect(page).toContain("label: 'Wachtend'");
  expect(page).toContain("label: 'Bezig'");
  expect(page).toContain("label: 'Mislukt'");
  expect(page).toContain('snapshot?.items.filter(isVisibleQueueItem)');
  expect(page).toContain('Geen openstaande taken.');
  expect(page).not.toContain('Verwerkt</');
  expect(page).not.toContain('Eigen verwerkingsbaan');
  expect(page).not.toContain('parallelle workers');
  expect(page).not.toContain('Totaal in transportwachtrij');
  expect(page).not.toContain('Dit is de ingestelde capaciteit');
  expect(page).not.toContain('Per pagina');
  expect(page).not.toContain('<select');
  expect(page).not.toContain('payload');
  expect(page).not.toContain('token');
});

test('builds bounded queue monitor paths and concise Dutch states', () => {
  expect(queueMonitorPath('all', 'open', 1, 50)).toBe(
    '/admin/queues?queue=all&state=open&page=1&per_page=50',
  );
  expect(queueMonitorPath('push', 'retrying', 0, 250)).toBe(
    '/admin/queues?queue=push&state=retrying&page=1&per_page=100',
  );
  expect(queueStateLabel('pending')).toBe('Wachtend');
  expect(queueStateLabel('queued')).toBe('Wachtend');
  expect(queueStateLabel('retrying')).toBe('Wachtend');
  expect(queueStateLabel('processing')).toBe('Bezig');
  expect(queueStateLabel('failed')).toBe('Mislukt');
  expect(queueStateTone('processing')).toBe('active');
  expect(queueStateTone('failed')).toBe('danger');
  expect(boundedQueueProgress(-3)).toBe(0);
  expect(boundedQueueProgress(44.6)).toBe(45);
  expect(boundedQueueProgress(180)).toBe(100);
  expect(boundedQueueProgress(null)).toBeNull();
});

test('never presents completed or cancelled work and exposes only server-approved actions', () => {
  for (const state of ['pending', 'queued', 'processing', 'retrying', 'failed'] as const) {
    expect(isVisibleQueueItem(queueItem(state))).toBe(true);
  }

  expect(isVisibleQueueItem(queueItem('completed'))).toBe(false);
  expect(isVisibleQueueItem(queueItem('cancelled'))).toBe(false);
  expect(queueActionForItem(queueItem('pending', ['start']))).toBe('start');
  expect(queueActionForItem(queueItem('queued', ['start']))).toBe('start');
  expect(queueActionForItem(queueItem('retrying'))).toBeNull();
  expect(queueActionForItem(queueItem('retrying', ['start']))).toBe('start');
  expect(queueActionForItem(queueItem('failed', ['retry']))).toBe('retry');
  expect(queueActionForItem(queueItem('failed'))).toBeNull();
  expect(queueActionForItem(queueItem('processing', ['start', 'retry']))).toBeNull();
  expect(queueActionForItem({ ...queueItem('pending'), available_actions: undefined as never })).toBeNull();
});

test('uses encoded queue action paths and permission-gated compact controls', () => {
  const page = readFileSync(new URL('../src/features/queues/QueuePage.tsx', import.meta.url), 'utf8');
  const item = { id: 'work/item', queue: 'push lane' };

  expect(queueActionPath(item, 'start')).toBe('/admin/queues/push%20lane/work%2Fitem/start');
  expect(queueActionPath(item, 'retry')).toBe('/admin/queues/push%20lane/work%2Fitem/retry');
  expect(page).toContain("hasPermission('system.queues.manage')");
  expect(page).toContain("queueMonitorPath('all', 'open'");
  expect(page).toContain("'Opnieuw starten' : 'Nu starten'");
  expect(page).toContain('aria-busy={busy}');
  expect(page).toContain('aria-label={`${actionLabel}: ${item.label}`}');
  expect(page).toContain('busyItemsRef.current.has(itemKey)');
  expect(page).toContain('role="status"');
  expect(page).toContain('actionStatusRef.current?.focus()');
  expect(page).toContain("aria-label={refreshing ? 'Wachtrij wordt ververst' : 'Wachtrij verversen'}");
});

test('formats measured duration, waiting time and active runtime without using update timestamps', () => {
  const item = queueItem('processing');

  expect(formatQueueDuration(850)).toBe('850 ms');
  expect(formatQueueDuration(18_640)).toBe('18,6 sec.');
  expect(formatQueueDuration(80_000)).toBe('1 min. 20 sec.');
  expect(formatQueueWait(item, '2026-07-24T10:00:10Z')).toBe('3 sec.');
  expect(formatQueueRuntime(item, '2026-07-24T10:00:10Z')).toBe('7 sec.');
  expect(formatQueueRuntime({ ...item, duration_ms: 18_640 }, '2026-07-24T10:00:10Z')).toBe('18,6 sec.');
});

test('keeps the bounded result cap visible without adding explanatory copy', () => {
  const page = readFileSync(new URL('../src/features/queues/QueuePage.tsx', import.meta.url), 'utf8');

  expect(page).toContain('is_truncated: boolean');
  expect(page).toContain("'is_truncated' in meta && meta.is_truncated === true");
  expect(page).toContain("{DUTCH_INTEGER.format(resource.pagination.total)}+ taken");
  expect(page).not.toContain('nieuwste');
});

test('uses the server refresh interval, avoids overlapping loads and pauses in hidden tabs', async () => {
  expect(queuePollIntervalMs(undefined)).toBe(QUEUE_DEFAULT_POLL_INTERVAL_MS);
  expect(queuePollIntervalMs(0)).toBe(QUEUE_MIN_POLL_INTERVAL_MS);
  expect(queuePollIntervalMs(120)).toBe(QUEUE_MAX_POLL_INTERVAL_MS);

  let hidden = false;
  let visibilityListener = () => undefined;
  let timerSequence = 0;
  const scheduled = new Map<number, { callback: () => void; delay: number }>();
  const pendingLoads: Array<(seconds: number) => void> = [];
  let loadCount = 0;

  const stop = startQueuePolling({
    load: () => new Promise<number>((resolve) => {
      loadCount += 1;
      pendingLoads.push(resolve);
    }),
    isHidden: () => hidden,
    schedule: (callback, delay) => {
      timerSequence += 1;
      scheduled.set(timerSequence, { callback, delay });
      return timerSequence;
    },
    cancel: (handle) => {
      scheduled.delete(handle);
    },
    subscribeVisibility: (listener) => {
      visibilityListener = listener;
      return () => {
        visibilityListener = () => undefined;
      };
    },
  });

  expect(loadCount).toBe(1);
  visibilityListener();
  expect(loadCount).toBe(1);

  pendingLoads.shift()?.(7);
  await Promise.resolve();
  await Promise.resolve();
  expect([...scheduled.values()][0]?.delay).toBe(7_000);

  hidden = true;
  visibilityListener();
  expect(scheduled.size).toBe(0);

  hidden = false;
  visibilityListener();
  expect(loadCount).toBe(2);

  stop();
  pendingLoads.shift()?.(5);
  await Promise.resolve();
  await Promise.resolve();
  expect(scheduled.size).toBe(0);
});
