import { expect, test } from 'playwright/test';
import type { SystemMetrics } from '../src/types/api';
import {
  appendSystemMetricSample,
  formatSystemBytes,
  formatSystemLoad,
  formatSystemPercent,
  SYSTEM_METRIC_HISTORY_LIMIT,
  systemMetricChartPaths,
} from '../src/features/system/systemMetricsPresentation';
import {
  startSystemMetricsPolling,
  SYSTEM_METRICS_POLL_INTERVAL_MS,
} from '../src/features/system/systemMetricsPolling';

test('keeps at most three minutes of live samples and replaces duplicate timestamps', () => {
  let history = [];
  for (let index = 0; index < 63; index += 1) {
    history = appendSystemMetricSample(history, metricsAt(index, index, 50));
  }

  expect(SYSTEM_METRICS_POLL_INTERVAL_MS).toBe(3_000);
  expect(SYSTEM_METRIC_HISTORY_LIMIT).toBe(60);
  expect(history).toHaveLength(60);
  expect(history[0]?.recordedAt).toBe(metricsAt(3, 0, 0).generated_at);
  expect(history.at(-1)?.recordedAt).toBe(metricsAt(62, 0, 0).generated_at);

  history = appendSystemMetricSample(history, metricsAt(62, 140, -10));
  expect(history).toHaveLength(60);
  expect(history.at(-1)).toMatchObject({ cpuUsagePercent: 100, memoryUsagePercent: 0 });

  const afterHiddenTab = appendSystemMetricSample(history, metricsAt(600, 20, 30));
  expect(afterHiddenTab).toHaveLength(1);
  expect(afterHiddenTab[0]?.recordedAt).toBe(metricsAt(600, 0, 0).generated_at);
});

test('builds bounded SVG paths and leaves a visible gap for missing measurements', () => {
  expect(systemMetricChartPaths([0, 50, null, 120], 100, 100)).toEqual([
    'M0 100 L33.33 50',
    'M100 0',
  ]);
  expect(systemMetricChartPaths([null, Number.NaN], 100, 100)).toEqual([]);
  expect(systemMetricChartPaths([], 100, 100)).toEqual([]);
});

test('formats percentages, load and binary storage sizes for Dutch administrators', () => {
  expect(formatSystemPercent(42.56)).toBe('42,6%');
  expect(formatSystemPercent(120)).toBe('100%');
  expect(formatSystemPercent(null)).toBe('-');
  expect(formatSystemLoad(1.5)).toBe('1,50');
  expect(formatSystemBytes(0)).toBe('0 B');
  expect(formatSystemBytes(1_073_741_824)).toBe('1 GiB');
  expect(formatSystemBytes(-1)).toBe('-');
});

test('polls only after completion, pauses while hidden and refreshes immediately when visible', async () => {
  let hidden = false;
  let visibilityListener = () => undefined;
  let nextTimer = 0;
  const scheduled = new Map<number, () => void>();
  const cancelled: number[] = [];
  const pendingLoads: Array<() => void> = [];
  let loadCount = 0;

  const stop = startSystemMetricsPolling({
    load: () => new Promise<void>((resolve) => {
      loadCount += 1;
      pendingLoads.push(resolve);
    }),
    isHidden: () => hidden,
    schedule: (callback) => {
      nextTimer += 1;
      scheduled.set(nextTimer, callback);
      return nextTimer;
    },
    cancel: (handle) => {
      cancelled.push(handle);
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
  expect(scheduled.size).toBe(0);

  pendingLoads.shift()?.();
  await Promise.resolve();
  await Promise.resolve();
  expect(scheduled.size).toBe(1);

  hidden = true;
  visibilityListener();
  expect(scheduled.size).toBe(0);
  expect(cancelled).toHaveLength(1);

  hidden = false;
  visibilityListener();
  expect(loadCount).toBe(2);
  expect(scheduled.size).toBe(0);

  stop();
  pendingLoads.shift()?.();
  await Promise.resolve();
  await Promise.resolve();
  expect(scheduled.size).toBe(0);
});

function metricsAt(second: number, cpu: number | null, memory: number | null): SystemMetrics {
  return {
    generated_at: new Date(Date.UTC(2026, 6, 16, 10, 0, second)).toISOString(),
    uptime_seconds: 86_400,
    cpu: {
      usage_percent: cpu,
      logical_processors: 4,
      load_average_1m: 0.42,
    },
    memory: {
      total_bytes: 16 * 1024 ** 3,
      used_bytes: 8 * 1024 ** 3,
      available_bytes: 8 * 1024 ** 3,
      usage_percent: memory,
    },
    disk: {
      label: 'DIS data',
      total_bytes: 100 * 1024 ** 3,
      used_bytes: 40 * 1024 ** 3,
      available_bytes: 60 * 1024 ** 3,
      usage_percent: 40,
    },
  };
}
