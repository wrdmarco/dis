import type { SystemMetrics } from '../../types/api';
import { SYSTEM_METRICS_POLL_INTERVAL_MS } from './systemMetricsPolling';

export const SYSTEM_METRIC_HISTORY_LIMIT = 60;

export interface SystemMetricHistorySample {
  recordedAt: string;
  cpuUsagePercent: number | null;
  memoryUsagePercent: number | null;
}

export function appendSystemMetricSample(
  current: SystemMetricHistorySample[],
  metrics: SystemMetrics,
  limit = SYSTEM_METRIC_HISTORY_LIMIT,
): SystemMetricHistorySample[] {
  const boundedLimit = Math.max(1, Math.floor(limit));
  const sample: SystemMetricHistorySample = {
    recordedAt: metrics.generated_at,
    cpuUsagePercent: normalizePercentage(metrics.cpu.usage_percent),
    memoryUsagePercent: normalizePercentage(metrics.memory.usage_percent),
  };
  const sampleTime = Date.parse(sample.recordedAt);
  const oldestAllowedTime = sampleTime - (SYSTEM_METRICS_POLL_INTERVAL_MS * boundedLimit);
  const next = current.filter((candidate) => {
    if (candidate.recordedAt === sample.recordedAt) {
      return false;
    }

    const candidateTime = Date.parse(candidate.recordedAt);
    return !Number.isFinite(sampleTime)
      || !Number.isFinite(candidateTime)
      || (candidateTime >= oldestAllowedTime && candidateTime <= sampleTime);
  });
  next.push(sample);
  next.sort((left, right) => left.recordedAt.localeCompare(right.recordedAt));

  return next.slice(-boundedLimit);
}

export function systemMetricChartPaths(
  values: Array<number | null>,
  width = 300,
  height = 92,
): string[] {
  if (values.length === 0 || width <= 0 || height <= 0) {
    return [];
  }

  const denominator = Math.max(values.length - 1, 1);
  const paths: string[] = [];
  let currentPath = '';

  values.forEach((rawValue, index) => {
    const value = normalizePercentage(rawValue);
    if (value === null) {
      if (currentPath !== '') {
        paths.push(currentPath);
        currentPath = '';
      }
      return;
    }

    const x = values.length === 1 ? width : (index / denominator) * width;
    const y = height - ((value / 100) * height);
    const point = `${roundCoordinate(x)} ${roundCoordinate(y)}`;
    currentPath += `${currentPath === '' ? 'M' : ' L'}${point}`;
  });

  if (currentPath !== '') {
    paths.push(currentPath);
  }

  return paths;
}

export function formatSystemBytes(value: number | null | undefined): string {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
    return '-';
  }

  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
  let scaled = value;
  let unitIndex = 0;
  while (scaled >= 1024 && unitIndex < units.length - 1) {
    scaled /= 1024;
    unitIndex += 1;
  }

  const maximumFractionDigits = unitIndex === 0 || scaled >= 100 ? 0 : 1;
  return `${new Intl.NumberFormat('nl-NL', { maximumFractionDigits, minimumFractionDigits: 0 }).format(scaled)} ${units[unitIndex]}`;
}

export function formatSystemPercent(value: number | null | undefined): string {
  const normalized = normalizePercentage(value);
  if (normalized === null) {
    return '-';
  }

  return `${new Intl.NumberFormat('nl-NL', { maximumFractionDigits: 1 }).format(normalized)}%`;
}

export function formatSystemLoad(value: number | null | undefined): string {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
    return '-';
  }

  return new Intl.NumberFormat('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
}

function normalizePercentage(value: number | null | undefined): number | null {
  if (typeof value !== 'number' || !Number.isFinite(value)) {
    return null;
  }

  return Math.min(100, Math.max(0, value));
}

function roundCoordinate(value: number): string {
  return Number(value.toFixed(2)).toString();
}
