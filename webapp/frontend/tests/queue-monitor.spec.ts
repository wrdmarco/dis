import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { QueueMonitorItem } from '../src/types/api';
import {
  boundedQueueProgress,
  formatQueueDuration,
  formatQueueRuntime,
  formatQueueWait,
  queueLaneDescription,
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

test('exposes Wachtrijen as a protected management page immediately before Systeem', () => {
  const route = readFileSync(new URL('../app/queues/page.tsx', import.meta.url), 'utf8');
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const routeShell = readFileSync(new URL('../src/next/RouteShell.tsx', import.meta.url), 'utf8');
  const queueIndex = navigation.indexOf("to: '/queues', label: 'Wachtrijen'");
  const systemIndex = navigation.indexOf("to: '/system', label: 'Systeem'");

  expect(route).toContain("permissions={['system.health.view']}");
  expect(queueIndex).toBeGreaterThan(-1);
  expect(systemIndex).toBeGreaterThan(queueIndex);
  expect(navigation).toContain("'/queues': () => import('../features/queues/QueuePage')");
  expect(routeShell).toContain("{ to: '/queues', permissions: ['system.health.view'] }");
});

test('moves queue monitoring out of raw System JSON and explains transport totals safely', () => {
  const systemPage = readFileSync(new URL('../src/features/system/SystemPage.tsx', import.meta.url), 'utf8');
  const queuePage = readFileSync(new URL('../src/features/queues/QueuePage.tsx', import.meta.url), 'utf8');

  expect(systemPage).not.toContain("'/admin/queues'");
  expect(systemPage).not.toContain('title="Queues"');
  expect(queuePage).toContain('Totaal in transportwachtrij');
  expect(queuePage).toContain("if (lane.key === 'push') return 'Pushmeldingen'");
  expect(queuePage).toContain("push_notification: 'Pushmelding'");
  expect(queuePage).toContain('Dit is de ingestelde capaciteit, geen live workerstatus.');
  expect(queuePage).not.toContain('payload');
  expect(queuePage).not.toContain('token');
});

test('builds bounded queue monitor filters and Dutch operational states', () => {
  expect(queueMonitorPath('push', 'retrying', 0, 250)).toBe(
    '/admin/queues?queue=push&state=retrying&page=1&per_page=100',
  );
  expect(queueStateLabel('pending')).toBe('In afwachting');
  expect(queueStateLabel('processing')).toBe('Wordt verwerkt');
  expect(queueStateLabel('completed')).toBe('Verwerkt');
  expect(queueStateTone('processing')).toBe('active');
  expect(queueStateTone('failed')).toBe('danger');
  expect(queueStateTone('cancelled')).toBe('neutral');
  expect(boundedQueueProgress(-3)).toBe(0);
  expect(boundedQueueProgress(44.6)).toBe(45);
  expect(boundedQueueProgress(180)).toBe(100);
  expect(boundedQueueProgress(null)).toBeNull();
});

test('describes parallel push processing without claiming live worker health', () => {
  expect(queueLaneDescription('push')).toContain('eigen parallelle workers');
  expect(queueLaneDescription('push')).toContain('vlotte alarmering');
  expect(queueLaneDescription('other')).toContain('Afzonderlijke serverwachtrij');
});

test('formats measured duration, waiting time and active runtime without using update timestamps', () => {
  const item: QueueMonitorItem = {
    id: 'safe-reference',
    queue: 'push',
    workload_type: 'push_notification',
    label: 'Pushmelding',
    state: 'processing',
    progress_percent: 35,
    queued_at: '2026-07-24T10:00:00Z',
    started_at: '2026-07-24T10:00:03Z',
    next_attempt_at: null,
    finished_at: null,
    attempts: 1,
    error_code: null,
    duration_ms: null,
  };

  expect(formatQueueDuration(850)).toBe('850 ms');
  expect(formatQueueDuration(18_640)).toBe('18,6 sec.');
  expect(formatQueueDuration(80_000)).toBe('1 min. 20 sec.');
  expect(formatQueueWait(item, '2026-07-24T10:00:10Z')).toBe('3 sec.');
  expect(formatQueueRuntime(item, '2026-07-24T10:00:10Z')).toBe('7 sec.');
  expect(formatQueueRuntime({ ...item, duration_ms: 18_640 }, '2026-07-24T10:00:10Z')).toBe('18,6 sec.');
});

test('renders unavailable transport telemetry and unknown attempts explicitly', () => {
  const types = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');
  const page = readFileSync(new URL('../src/features/queues/QueuePage.tsx', import.meta.url), 'utf8');

  expect(types).toContain('transport_pending_count: number | null');
  expect(types).toContain('transport_failed_count: number | null');
  expect(types).toContain('attempts: number | null');
  expect(page).toContain('Niet beschikbaar');
  expect(page).toContain("item.attempts ?? '-'");
});

test('makes the safe 2000-item cap explicit instead of implying an exhaustive queue list', () => {
  const page = readFileSync(new URL('../src/features/queues/QueuePage.tsx', import.meta.url), 'utf8');

  expect(page).toContain('is_truncated: boolean');
  expect(page).toContain("'is_truncated' in meta && meta.is_truncated === true");
  expect(page).toContain("resource.pagination.is_truncated ? '+' : ''");
  expect(page).toContain('nieuwste {DUTCH_INTEGER.format(resource.pagination.total)} zichtbaar');
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
